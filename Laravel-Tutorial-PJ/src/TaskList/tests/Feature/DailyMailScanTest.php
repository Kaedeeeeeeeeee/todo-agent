<?php

namespace Tests\Feature;

use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use App\Services\GmailConnectionManager;
use App\Services\TaskExtractionManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyMailScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        Http::preventStrayRequests();
        config(['services.task_agent.token' => str_repeat('x', 64), 'services.task_agent.url' => 'http://agent.test', 'services.task_agent.account' => 'owner@example.test']);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        config(['services.task_agent.owner_id' => $user->id]);
        GmailConnection::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        return $user;
    }

    private function messages(int $count): array
    {
        return array_map(fn ($i) => ['id' => dechex(1000 + $i), 'subject' => 'Review '.$i, 'from_' => 'work@example.test',
            'email_ts' => '2026-11-18T10:00:00Z', 'display_url' => 'https://mail.google.com/mail/u/?authuser=owner%40example.test#all/'.dechex(1000 + $i)], range(1, $count));
    }

    private function batch(array $messages): array
    {
        return ['status' => 'succeeded', 'read_count' => count($messages), 'processed_ids' => array_column($messages, 'id'), 'skipped_count' => 0,
            'candidates' => array_map(fn ($mail) => ['message_id' => $mail['id'], 'title' => '確認 '.$mail['id'], 'description' => 'Review request',
                'reason' => 'Action requested', 'evidence' => 'Please review', 'subject' => $mail['subject'], 'sender' => $mail['from_'], 'received_at' => $mail['email_ts'], 'due_date' => null], $messages)];
    }

    public function test_full_day_is_paginated_then_processed_in_one_run_beyond_six_messages(): void
    {
        $user = $this->owner();
        $mails = $this->messages(14);
        $results = [];
        Http::fake(['agent.test/runs/*' => function ($request) use (&$results, $mails) {
            if ($request->method() === 'POST') {
                $results[$request->url()] = $request['kind'] === 'list'
                    ? ['status' => 'succeeded', 'messages' => array_slice($mails, $request['scan']['page_token'] ? 7 : 0, 7), 'next_page_token' => $request['scan']['page_token'] ? null : 'page-two']
                    : $this->batch($request['scan']['messages']);

                return Http::response(['status' => 'reading'], 202);
            }

            return Http::response($results[$request->url()]);
        }]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($user, 'GITHUB');
        $this->assertSame(86400, $run->scan_options['before'] - $run->scan_options['after']);
        $manager->refresh($run);
        $this->assertNull($run->total_count);
        $this->travel(1)->minutes();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertSame(14, $run->fresh()->total_count);
        $this->travel(1)->minutes();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->artisan('tasklist:process-mail')->assertSuccessful();
        $this->assertSame('reading', $run->fresh()->status);
        $this->assertSame(6, $run->fresh()->scanned_count);
        $this->assertSame(6, $run->fresh()->candidate_count);
        for ($i = 0; $i < 2; $i++) {
            $this->travel(1)->minutes();
            $this->artisan('tasklist:process-mail')->assertSuccessful();
            $this->artisan('tasklist:process-mail')->assertSuccessful();
        }
        $done = $run->fresh();
        $this->assertSame('succeeded', $done->status);
        $this->assertSame(14, $done->scanned_count);
        $this->assertSame(14, $done->candidate_count);
        $this->assertSame(3, $done->batch_count);
        $this->assertFalse($done->has_more);
        $this->assertDatabaseCount('task_extractions', 1);
        $this->assertDatabaseCount('task_candidates', 14);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(10);
    }

    public function test_failed_second_batch_resumes_without_reimporting_completed_batch(): void
    {
        $user = $this->owner();
        $mails = $this->messages(8);
        $results = [];
        $failed = false;
        Http::fake(['agent.test/runs/*' => function ($request) use (&$results, &$failed, $mails) {
            if ($request->method() === 'POST') {
                if ($request['kind'] === 'list') {
                    $result = ['status' => 'succeeded', 'messages' => $mails, 'next_page_token' => null];
                } elseif (count($request['scan']['messages']) === 2 && ! $failed) {
                    $failed = true;
                    $result = ['status' => 'failed', 'error' => '一時的な読み取り制限'];
                } else {
                    $result = $this->batch($request['scan']['messages']);
                }
                $results[$request->url()] = $result;

                return Http::response(['status' => 'reading'], 202);
            }

            return Http::response($results[$request->url()]);
        }]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($user, 'GITHUB');
        $manager->refresh($run);
        for ($i = 0; $i < 2; $i++) {
            $this->travel(1)->minutes();
            $manager->refresh($run);
            $manager->refresh($run);
        }
        $this->assertSame('failed', $run->status);
        $this->assertSame(6, $run->scanned_count);
        $this->assertDatabaseCount('task_candidates', 6);
        $retry = $manager->start($user, 'GITHUB', 'manual', $run);
        $this->assertSame($run->scan_options, $retry->scan_options);
        $this->assertSame(6, $retry->scanned_count);
        $manager->refresh($retry);
        $manager->refresh($retry);
        $this->assertSame('succeeded', $retry->status);
        $this->assertSame(8, $retry->scanned_count);
        $this->assertSame(8, $retry->candidate_count);
        $this->assertDatabaseCount('task_candidates', 8);
        $this->assertDatabaseCount('gmail_processed_messages', 8);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(8);
    }

    public function test_duplicate_batch_poll_does_not_double_count_and_empty_window_finishes(): void
    {
        $user = $this->owner();
        Http::fake(['agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)->push(['status' => 'succeeded', 'messages' => [], 'next_page_token' => null])]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($user, 'BIZMATES');
        $manager->refresh($run);
        $manager->refresh($run);
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(0, $run->total_count);
        $this->assertSame(0, $run->scanned_count);
        $this->assertDatabaseCount('task_candidates', 0);
        Http::assertSentCount(2);
    }

    public function test_repeated_search_cursor_is_rejected_without_losing_saved_listing(): void
    {
        $user = $this->owner();
        Http::fake(['agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)
            ->push(['status' => 'succeeded', 'messages' => $this->messages(1), 'next_page_token' => 'again'])
            ->push(['status' => 'reading'], 202)->push(['status' => 'succeeded', 'messages' => [], 'next_page_token' => 'again'])]);
        $manager = app(TaskExtractionManager::class);
        $run = $manager->start($user, 'GITHUB');
        $manager->refresh($run);
        $this->travel(1)->minutes();
        $manager->refresh($run);
        $manager->refresh($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertCount(1, $run->fresh()->mail_plan);
        $this->assertDatabaseCount('task_candidates', 0);
        Http::assertSentCount(4);
    }

    public function test_all_pending_candidates_are_visible_after_large_scan(): void
    {
        $user = $this->owner();
        TaskCandidate::factory()->count(55)->create(['user_id' => $user->id]);
        $this->postJson('/graphql', ['query' => '{ candidateInbox { candidates { id } } }'])
            ->assertJsonMissingPath('errors')->assertJsonCount(55, 'data.candidateInbox.candidates');
        Http::assertNothingSent();
    }

    public function test_disconnect_cancels_current_batch_and_never_starts_queued_continuation(): void
    {
        $user = $this->owner();
        $batchId = (string) Str::uuid();
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'reading', 'stage' => 'processing',
            'batch_id' => $batchId, 'next_batch_at' => now(), 'scan_options' => ['after' => 100, 'before' => 200, 'page_token' => null],
            'mail_plan' => $this->messages(1), 'total_count' => 1]);
        Http::fake(['agent.test/*' => Http::response(['cancelled' => true])]);
        app(GmailConnectionManager::class)->disconnect($user, true);
        app(TaskExtractionManager::class)->refresh($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseCount('task_candidates', 0);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), $batchId));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_unexpected_batch_message_is_rejected_without_advancing_progress(): void
    {
        $user = $this->owner();
        $run = TaskExtraction::factory()->create(['user_id' => $user->id, 'status' => 'reading', 'stage' => 'processing',
            'batch_id' => (string) Str::uuid(), 'batch_started_at' => now(),
            'mail_plan' => $this->messages(1), 'total_count' => 1]);
        $bad = $this->batch($this->messages(2));
        Http::fake(['agent.test/runs/*' => Http::response($bad)]);
        app(TaskExtractionManager::class)->refresh($run);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->scanned_count);
        $this->assertDatabaseCount('task_candidates', 0);
        Http::assertSentCount(1);
    }
}
