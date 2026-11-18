<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use App\Services\GmailAutomation;
use App\Services\TaskExtractionManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeneralTaskSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18T03:00:00Z'));
        config(['services.task_agent.token' => str_repeat('x', 64), 'services.task_agent.url' => 'http://agent.test', 'services.task_agent.account' => 'owner@example.test']);
        Http::preventStrayRequests();
    }

    private function owner(bool $connected = true): User
    {
        $user = User::factory()->create();
        config(['services.task_agent.owner_id' => $user->id]);
        if ($connected) {
            GmailConnection::factory()->create(['user_id' => $user->id]);
        }
        $this->actingAs($user);

        return $user;
    }

    public function test_all_sources_freeze_both_windows_and_pass_user_conditions_to_reader(): void
    {
        $this->owner();
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);

        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: ALL, instructions: "返信と次の作業を優先") { scope status instructions } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.extractGmailTasks.scope', 'ALL')->assertJsonPath('data.extractGmailTasks.instructions', '返信と次の作業を優先');
        $run = TaskExtraction::firstOrFail();
        $this->assertSame('2026-11-17T03:00:00+00:00', Carbon::createFromTimestamp($run->scan_options['after'], 'UTC')->toIso8601String());
        $this->assertSame('2026-11-16T15:00:00+00:00', Carbon::createFromTimestamp($run->scan_options['codex_after'], 'UTC')->toIso8601String());
        $this->assertSame('2026-11-17T15:00:00+00:00', Carbon::createFromTimestamp($run->scan_options['codex_before'], 'UTC')->toIso8601String());
        Http::assertSent(fn ($request) => $request['scope'] === 'ALL' && $request['scan']['instructions'] === '返信と次の作業を優先');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_combined_results_keep_both_original_sources_and_still_require_confirmation(): void
    {
        $owner = $this->owner();
        $mailId = 'abc123';
        $codexId = str_repeat('d', 64);
        $url = 'codex://threads/11111111-1111-4111-8111-111111111111';
        $metadata = fn ($id, $source, $link) => ['id' => $id, 'source_type' => $source, 'subject' => 'Action request', 'from_' => 'Source', 'email_ts' => '2026-11-17T10:00:00Z', 'display_url' => $link];
        $candidate = fn ($id) => ['message_id' => $id, 'title' => '対応を確認する', 'description' => 'Check pending work.', 'due_date' => null, 'reason' => 'Unverified action.', 'evidence' => 'Please check.', 'subject' => 'Action request', 'sender' => 'Source', 'received_at' => '2026-11-17T10:00:00Z'];
        Http::fake(['agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)
            ->push(['status' => 'succeeded', 'messages' => [$metadata($mailId, 'GMAIL', 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/abc123')], 'next_page_token' => '{"source":"CODEX","token":null}'])
            ->push(['status' => 'reading'], 202)
            ->push(['status' => 'succeeded', 'messages' => [$metadata($codexId, 'CODEX', $url)], 'next_page_token' => null])
            ->push(['status' => 'reading'], 202)
            ->push(['status' => 'succeeded', 'read_count' => 2, 'skipped_count' => 0, 'processed_ids' => [$mailId, $codexId], 'candidates' => [$candidate($mailId), $candidate($codexId)]])]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($owner, 'ALL');
        $manager->refresh($run);
        $this->travel(1)->minutes();
        $manager->refresh($run);
        $manager->refresh($run);
        $this->travel(1)->minutes();
        $manager->refresh($run);
        $manager->refresh($run);

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(2, $run->scanned_count);
        $this->assertDatabaseHas('task_candidates', ['message_id' => $mailId, 'source_type' => 'GMAIL', 'source_url' => null]);
        $this->assertDatabaseHas('task_candidates', ['message_id' => $codexId, 'source_type' => 'CODEX', 'source_url' => $url]);
        $this->postJson('/graphql', ['query' => '{ candidateInbox(scope: ALL) { latest_run { scope } candidates { id source_type } } }'])
            ->assertJsonMissingPath('errors')->assertJsonCount(2, 'data.candidateInbox.candidates');
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(6);
    }

    public function test_new_conditions_reconsider_previously_processed_content_without_losing_deduplication(): void
    {
        $owner = $this->owner();
        DB::table('gmail_processed_messages')->insert(['user_id' => $owner->id, 'scope' => 'GENERAL:e3b0c44298fc1c14', 'message_id' => 'abc123', 'processed_at' => now()]);
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);

        app(TaskExtractionManager::class)->start($owner, 'MAIL', instructions: '返信だけを探す');

        Http::assertSent(fn ($request) => $request['scan']['instructions'] === '返信だけを探す' && $request['scan']['exclude_ids'] === []);
        $this->assertDatabaseCount('gmail_processed_messages', 1);
    }

    public function test_scheduled_conditions_are_saved_and_used_for_automatic_runs(): void
    {
        $owner = $this->owner();
        $this->postJson('/graphql', ['query' => 'mutation { updateGmailAutomation(input: {enabled:true, time:"09:00", scope:ALL, instructions:"未完了の仕事を優先"}) { scope instructions } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.updateGmailAutomation.instructions', '未完了の仕事を優先');
        GmailConnection::where('user_id', $owner->id)->update(['next_run_at' => now()]);
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);

        app(GmailAutomation::class)->tick();

        $this->assertSame('未完了の仕事を優先', TaskExtraction::firstOrFail()->scan_options['instructions']);
        Http::assertSent(fn ($request) => $request['scope'] === 'ALL' && $request['scan']['instructions'] === '未完了の仕事を優先');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_saved_candidates_can_be_confirmed_while_remaining_content_is_still_processing(): void
    {
        $owner = $this->owner();
        $run = TaskExtraction::factory()->create(['user_id' => $owner->id, 'scope' => 'ALL', 'status' => 'reading', 'stage' => 'processing', 'next_batch_at' => now()->addMinute()]);
        $candidate = TaskCandidate::factory()->create(['user_id' => $owner->id, 'task_extraction_id' => $run->id]);
        $folder = Folder::factory()->create(['user_id' => $owner->id]);

        $this->postJson('/graphql', ['query' => 'mutation($id:ID!,$folder:ID!){acceptTaskCandidate(id:$id,folder_id:$folder,input:{title:"確認した作業",due_date:"2026-11-19"}){title}}', 'variables' => ['id' => (string) $candidate->id, 'folder' => (string) $folder->id]])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.acceptTaskCandidate.title', '確認した作業');

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('task_candidates', ['id' => $candidate->id, 'status' => 'accepted']);
        $this->assertSame('reading', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_long_conditions_are_rejected_before_any_read_is_started(): void
    {
        $this->owner();
        $this->postJson('/graphql', ['query' => 'mutation($text:String!) { extractGmailTasks(scope:MAIL, instructions:$text) { id } }', 'variables' => ['text' => str_repeat('長', 2001)]])
            ->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->postJson('/graphql', ['query' => 'mutation($text:String!) { updateGmailAutomation(input:{enabled:false,time:"09:00",scope:ALL,instructions:$text}) { enabled } }', 'variables' => ['text' => str_repeat('長', 2001)]])
            ->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('task_extractions', 0);
        Http::assertNothingSent();
    }

    public function test_all_sources_require_mail_connection_and_local_owner_access(): void
    {
        $this->owner(false);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope:ALL) { id } }'])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope:MAIL) { id } }'])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => '{ candidateInbox(scope:ALL) { enabled candidates { id } } }'])
            ->assertJsonPath('data.candidateInbox.enabled', false)->assertJsonCount(0, 'data.candidateInbox.candidates');
        $this->assertDatabaseCount('task_extractions', 0);
        Http::assertNothingSent();
    }
}
