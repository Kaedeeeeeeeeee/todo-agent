<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use App\Services\GmailConnectionManager;
use App\Services\TaskExtractionManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CodexHistoryGraphQLTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = '{ candidateInbox { enabled codex_available account run { scope status } candidates { id source_type source_url title } } }';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18T12:00:00Z'));
        config(['services.task_agent.token' => str_repeat('x', 64), 'services.task_agent.url' => 'http://agent.test', 'services.task_agent.account' => 'owner@example.test']);
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        config(['services.task_agent.owner_id' => $owner->id]);
        $this->actingAs($owner);

        return $owner;
    }

    public function test_owner_can_read_yesterdays_codex_without_gmail_connected(): void
    {
        $owner = $this->owner();
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: CODEX) { scope status } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.extractGmailTasks.scope', 'CODEX');
        $run = TaskExtraction::firstOrFail();
        $this->assertSame('2026-11-16T15:00:00+00:00', Carbon::createFromTimestamp($run->scan_options['after'], 'UTC')->toIso8601String());
        $this->assertSame('2026-11-17T15:00:00+00:00', Carbon::createFromTimestamp($run->scan_options['before'], 'UTC')->toIso8601String());
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $owner->id, 'status' => 'disconnected']);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['scope'] === 'CODEX' && $request['kind'] === 'list');
    }

    public function test_codex_candidates_keep_source_links_and_require_confirmation_before_task_creation(): void
    {
        $owner = $this->owner();
        $id = str_repeat('a', 64);
        $link = 'codex://threads/11111111-1111-4111-8111-111111111111';
        Http::fake(['agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)
            ->push(['status' => 'succeeded', 'messages' => [['id' => $id, 'subject' => 'Example project', 'from_' => 'Codex · project', 'email_ts' => '2026-11-17T02:00:00Z', 'display_url' => $link]], 'next_page_token' => null])
            ->push(['status' => 'reading'], 202)->push(['status' => 'succeeded', 'read_count' => 1, 'skipped_count' => 0, 'processed_ids' => [$id],
                'candidates' => [['message_id' => $id, 'title' => '再試行ボタンを実装する', 'description' => 'Pending implementation.', 'due_date' => null,
                    'subject' => 'Example project', 'sender' => 'Codex · project', 'received_at' => '2026-11-17T02:00:00Z', 'reason' => 'Yesterday request remains unverified.', 'evidence' => 'Please add a retry button.']]])]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($owner, 'CODEX');
        $manager->refresh($run);
        $this->travel(1)->minutes();
        $manager->refresh($run);
        $manager->refresh($run);
        $this->assertSame('succeeded', $run->status);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('task_candidates', ['source_type' => 'CODEX', 'source_url' => $link, 'status' => 'pending']);
        $candidate = TaskCandidate::firstOrFail();
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonPath('data.candidateInbox.codex_available', true)
            ->assertJsonPath('data.candidateInbox.account', null)->assertJsonPath('data.candidateInbox.candidates.0.source_url', $link);
        $folder = Folder::factory()->create(['user_id' => $owner->id]);
        $mutation = 'mutation($id: ID!, $folder: ID!, $input: CreateTaskInput!) { acceptTaskCandidate(id: $id, folder_id: $folder, input: $input) { id title } }';
        $variables = ['id' => (string) $candidate->id, 'folder' => (string) $folder->id, 'input' => ['title' => $candidate->title, 'due_date' => '2026-11-20']];
        $first = $this->postJson('/graphql', ['query' => $mutation, 'variables' => $variables])->assertJsonMissingPath('errors');
        $this->postJson('/graphql', ['query' => $mutation, 'variables' => $variables])->assertJsonPath('data.acceptTaskCandidate.id', $first->json('data.acceptTaskCandidate.id'));
        $this->assertDatabaseCount('tasks', 1);
        Http::assertSentCount(4);
    }

    public function test_other_accounts_cannot_read_local_history_or_see_owner_candidates(): void
    {
        $owner = $this->owner();
        TaskCandidate::factory()->create(['user_id' => $owner->id, 'source_type' => 'CODEX']);
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::INBOX])
            ->assertJsonPath('data.candidateInbox.enabled', false)->assertJsonPath('data.candidateInbox.codex_available', false)->assertJsonCount(0, 'data.candidateInbox.candidates');
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: CODEX) { id } }'])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        Http::assertNothingSent();
    }

    public function test_disconnecting_gmail_does_not_cancel_codex_runs_or_hide_codex_candidates(): void
    {
        $owner = $this->owner();
        GmailConnection::factory()->create(['user_id' => $owner->id, 'schedule_enabled' => true, 'schedule_scope' => 'CODEX', 'next_run_at' => now()->addHour()]);
        $run = TaskExtraction::factory()->create(['user_id' => $owner->id, 'scope' => 'CODEX', 'stage' => 'listing', 'status' => 'reading', 'batch_id' => fake()->uuid()]);
        TaskCandidate::factory()->create(['user_id' => $owner->id, 'task_extraction_id' => $run->id, 'source_type' => 'CODEX']);
        TaskCandidate::factory()->create(['user_id' => $owner->id, 'source_type' => 'GMAIL']);
        Http::fake(['agent.test/*' => Http::response(['status' => 'reading'])]);
        app(GmailConnectionManager::class)->disconnect($owner, true);
        $this->assertSame('reading', $run->fresh()->status);
        $this->assertTrue(GmailConnection::first()->schedule_enabled);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonCount(1, 'data.candidateInbox.candidates')
            ->assertJsonPath('data.candidateInbox.candidates.0.source_type', 'CODEX');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_codex_can_be_scheduled_while_gmail_is_disconnected(): void
    {
        $owner = $this->owner();
        $this->postJson('/graphql', ['query' => 'mutation { updateGmailAutomation(input: {enabled: true, time: "09:00", scope: CODEX}) { enabled scope } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.updateGmailAutomation.scope', 'CODEX');
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $owner->id, 'status' => 'disconnected', 'schedule_enabled' => true, 'schedule_scope' => 'CODEX']);
        Http::assertNothingSent();
    }
}
