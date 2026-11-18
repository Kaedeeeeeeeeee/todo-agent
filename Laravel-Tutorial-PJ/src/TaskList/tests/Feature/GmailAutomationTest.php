<?php

namespace Tests\Feature;

use App\Models\GmailConnection;
use App\Models\TaskExtraction;
use App\Models\User;
use App\Services\GmailConnectionManager;
use App\Services\TaskExtractionManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GmailAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = '{ gmailAutomation { enabled time scope next_run_at worker_online } gmailExtractionHistory { id status } }';

    private const SAVE = 'mutation($input: GmailAutomationInput!) { updateGmailAutomation(input: $input) { enabled time scope next_run_at } }';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 23:59:30', 'UTC'));
        Http::preventStrayRequests();
        config(['services.task_agent.token' => str_repeat('x', 64), 'services.task_agent.url' => 'http://agent.test', 'services.task_agent.account' => 'owner@example.test']);
    }

    private function owner(array $settings = []): User
    {
        $user = User::factory()->create();
        config(['services.task_agent.owner_id' => $user->id]);
        GmailConnection::factory()->create(array_merge(['user_id' => $user->id], $settings));
        $this->actingAs($user);

        return $user;
    }

    private function agentResult(array $overrides = []): array
    {
        return array_merge(['status' => 'succeeded', 'read_count' => 1, 'processed_ids' => ['abc123'], 'skipped_count' => 0, 'next_page_token' => null,
            'candidates' => [['message_id' => 'abc123', 'title' => '内容を確認する', 'description' => 'Review the change.', 'due_date' => null,
                'subject' => 'Review request', 'sender' => 'work@example.test', 'received_at' => '2026-11-18', 'reason' => 'Review requested.', 'evidence' => 'Please review.']]], $overrides);
    }

    public function test_settings_require_login_and_connected_owner_and_history_is_isolated(): void
    {
        $this->postJson('/graphql', ['query' => self::SETTINGS])->assertUnauthorized();
        $owner = $this->owner();
        TaskExtraction::factory()->create(['user_id' => $owner->id]);
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::SETTINGS])
            ->assertJsonPath('data.gmailExtractionHistory', [])->assertJsonPath('data.gmailAutomation.enabled', false);
        $this->postJson('/graphql', ['query' => self::SAVE, 'variables' => ['input' => ['enabled' => true, 'time' => '09:00', 'scope' => 'BIZMATES']]])
            ->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        Http::assertNothingSent();
    }

    public function test_saving_schedule_uses_japan_time_and_pausing_clears_pending_retries(): void
    {
        $user = $this->owner();
        $input = ['enabled' => true, 'time' => '09:01', 'scope' => 'BIZMATES'];
        // 23:59 UTC is 08:59 JST on the following day.
        $this->postJson('/graphql', ['query' => self::SAVE, 'variables' => ['input' => $input]])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.updateGmailAutomation.next_run_at', '2026-11-19T09:01:00+09:00');
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $user->id, 'schedule_enabled' => true, 'schedule_time' => '09:01']);
        $failed = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'failed', 'retry_at' => now()->addMinutes(2)]);
        $this->postJson('/graphql', ['query' => self::SAVE, 'variables' => ['input' => array_merge($input, ['enabled' => false])]])
            ->assertJsonPath('data.updateGmailAutomation.next_run_at', null)->assertJsonPath('data.updateGmailAutomation.enabled', false);
        $this->assertNull($failed->fresh()->retry_at);
        Http::assertNothingSent();
    }

    public function test_invalid_time_does_not_enable_schedule(): void
    {
        $user = $this->owner();
        $this->postJson('/graphql', ['query' => self::SAVE, 'variables' => ['input' => ['enabled' => true, 'time' => '25:00', 'scope' => 'GITHUB']]])
            ->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $user->id, 'schedule_enabled' => false, 'next_run_at' => null]);
        Http::assertNothingSent();
    }

    public function test_due_schedule_starts_once_and_background_command_saves_results_without_a_page(): void
    {
        $user = $this->owner(['schedule_enabled' => true, 'schedule_time' => '10:00', 'schedule_scope' => 'BIZMATES', 'next_run_at' => now()]);
        Http::fake(['agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)->push(['status' => 'succeeded', 'messages' => [['id' => 'abc123', 'subject' => 'Review', 'from_' => 'work@example.test', 'email_ts' => '2026-11-18T23:00:00Z', 'display_url' => 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/abc123']], 'next_page_token' => null])->push(['status' => 'reading'], 202)->push($this->agentResult())]);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertDatabaseHas('task_extractions', ['user_id' => $user->id, 'trigger' => 'scheduled', 'status' => 'reading']);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->travel(1)->minutes();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertDatabaseCount('task_extractions', 1);
        $this->assertDatabaseHas('task_candidates', ['user_id' => $user->id, 'message_id' => 'abc123', 'status' => 'pending']);
        $this->assertDatabaseHas('gmail_processed_messages', ['user_id' => $user->id, 'message_id' => 'abc123']);
        $this->assertDatabaseCount('tasks', 0);
        $this->postJson('/graphql', ['query' => self::SETTINGS])->assertJsonPath('data.gmailAutomation.worker_online', true)
            ->assertJsonPath('data.gmailExtractionHistory.0.status', 'succeeded');
        Http::assertSentCount(4);
    }

    public function test_future_schedule_and_disabled_schedule_do_not_read_mail(): void
    {
        $this->owner(['schedule_enabled' => true, 'next_run_at' => now()->addMinute()]);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        GmailConnection::query()->update(['schedule_enabled' => false, 'next_run_at' => now()->subMinute()]);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertDatabaseCount('task_extractions', 0);
        Http::assertNothingSent();
    }

    public function test_two_automatic_retries_stop_after_third_failure_without_advancing_cursor(): void
    {
        $user = $this->owner(['schedule_enabled' => true]);
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'trigger' => 'scheduled', 'status' => 'reading', 'stage' => 'listing', 'batch_id' => (string) Str::uuid(), 'batch_started_at' => now(), 'scan_options' => ['after' => 100, 'before' => 200, 'page_token' => null]]);
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'failed', 'error' => '読み取り制限です。'])]);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertSame(2, (int) $run->fresh()->retry_at->diffInMinutes(now(), true));
        $this->travel(3)->minutes();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertDatabaseCount('task_extractions', 3);
        $last = TaskExtraction::where('attempt', 3)->firstOrFail();
        $this->assertSame('failed', $last->status);
        $this->assertNull($last->retry_at);
        $this->assertSame(['after' => 100, 'before' => 200, 'page_token' => null], $last->scan_options);
        $this->assertNull(GmailConnection::first()->scan_state);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(5);
    }

    public function test_new_run_ignores_legacy_backlog_and_only_lists_the_past_day(): void
    {
        $user = $this->owner(['scan_state' => ['GITHUB' => ['after' => 100, 'before' => 200, 'page_token' => 'legacy-backlog']]]);
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);
        $run = app(TaskExtractionManager::class)->start($user, 'GITHUB');
        $this->assertSame(86400, $run->scan_options['before'] - $run->scan_options['after']);
        $this->assertNull($run->scan_options['page_token']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['kind'] === 'list' && $request['scan']['page_token'] === null);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_processed_nonactionable_emails_are_excluded_on_subsequent_scans(): void
    {
        $user = $this->owner();
        DB::table('gmail_processed_messages')->insert(['user_id' => $user->id, 'scope' => 'GITHUB', 'message_id' => 'abc123', 'processed_at' => now()]);
        Http::fake(['agent.test/runs/*' => Http::response(['status' => 'reading'], 202)]);
        app(TaskExtractionManager::class)->start($user, 'GITHUB');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['scan']['exclude_ids'] === ['abc123']);
    }

    public function test_disconnect_disables_schedule_and_prevents_late_result_import(): void
    {
        $user = $this->owner(['schedule_enabled' => true, 'next_run_at' => now()]);
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'reading']);
        Http::fake(['agent.test/*' => Http::response($this->agentResult())]);
        app(GmailConnectionManager::class)->disconnect($user, true);
        app(TaskExtractionManager::class)->refresh($run);
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $user->id, 'schedule_enabled' => false, 'next_run_at' => null]);
        $this->assertDatabaseCount('task_candidates', 0);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_retry_is_owner_scoped_and_can_only_be_submitted_once(): void
    {
        $user = $this->owner();
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'failed', 'created_at' => now()->subHour()]);
        $foreign = TaskExtraction::factory()->create(['status' => 'failed']);
        $mutation = 'mutation($id: ID!) { retryGmailExtraction(id: $id) { id retry_of status } }';
        $this->postJson('/graphql', ['query' => $mutation, 'variables' => ['id' => $foreign->id]])->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
        Http::fake(['agent.test/*' => Http::response(['status' => 'reading'], 202)]);
        $this->postJson('/graphql', ['query' => $mutation, 'variables' => ['id' => $run->id]])->assertJsonMissingPath('errors')->assertJsonPath('data.retryGmailExtraction.retry_of', $run->id);
        $this->postJson('/graphql', ['query' => $mutation, 'variables' => ['id' => $run->id]])->assertJsonPath('errors.0.extensions.code', 'CONFLICT');
        Http::assertSentCount(1);
    }

    public function test_temporary_result_network_failure_keeps_the_read_active_until_timeout(): void
    {
        $user = $this->owner();
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'reading']);
        Http::fake(['agent.test/runs/*' => Http::failedConnection()]);
        app(TaskExtractionManager::class)->refresh($run);
        $this->assertSame('reading', $run->fresh()->status);
        $this->travel(6)->minutes();
        app(TaskExtractionManager::class)->refresh($run);
        $this->assertSame('failed', $run->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_old_failed_run_cannot_rewind_a_newer_successful_scan(): void
    {
        $user = $this->owner();
        $failed = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'failed', 'created_at' => now()->subHour()]);
        TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'succeeded', 'created_at' => now()->subMinutes(5)]);
        $this->postJson('/graphql', ['query' => 'mutation($id: ID!) { retryGmailExtraction(id: $id) { id } }', 'variables' => ['id' => $failed->id]])
            ->assertJsonPath('errors.0.extensions.code', 'CONFLICT');
        $this->assertDatabaseCount('task_extractions', 2);
        Http::assertNothingSent();
    }
}
