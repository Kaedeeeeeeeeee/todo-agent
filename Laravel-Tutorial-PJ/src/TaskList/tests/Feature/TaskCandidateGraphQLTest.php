<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskCandidateGraphQLTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = '{ candidateInbox { enabled account run { id status read_count candidate_count duplicate_count error } candidates { id title due_date status evidence } } }';

    private const ACCEPT = 'mutation($id: ID!, $folder: ID!, $input: CreateTaskInput!) { acceptTaskCandidate(id: $id, folder_id: $folder, input: $input) { id title due_date folder_id } }';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.task_agent.token' => str_repeat('x', 64), 'services.task_agent.url' => 'http://agent.test', 'services.task_agent.account' => 'owner@example.test']);
    }

    private function connect(User $user): void
    {
        config(['services.task_agent.owner_id' => $user->id]);
        GmailConnection::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
    }

    public function test_requires_login_and_does_not_expose_connected_mailbox_to_other_accounts(): void
    {
        $this->postJson('/graphql', ['query' => self::INBOX])->assertUnauthorized();
        $candidate = TaskCandidate::factory()->create();
        config(['services.task_agent.owner_id' => $candidate->user_id]);
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::INBOX])->assertJsonPath('data.candidateInbox', [
            'enabled' => false, 'account' => null, 'run' => null, 'candidates' => [],
        ]);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: GITHUB) { id } }'])
            ->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        Http::assertNothingSent();
    }

    public function test_starts_read_only_extraction_and_reuses_an_active_run(): void
    {
        $this->connect(User::factory()->create());
        Http::fake(['agent.test/*' => Http::response(['status' => 'reading'], 202)]);
        $query = 'mutation { extractGmailTasks(scope: GITHUB) { id status read_count candidate_count duplicate_count } }';
        $first = $this->postJson('/graphql', ['query' => $query])->assertJsonMissingPath('errors')->assertJsonPath('data.extractGmailTasks.status', 'reading')->assertJsonPath('data.extractGmailTasks.read_count', 0);
        $this->postJson('/graphql', ['query' => $query])->assertJsonPath('data.extractGmailTasks.id', $first->json('data.extractGmailTasks.id'));
        $this->assertDatabaseCount('task_extractions', 1);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['scope'] === 'GITHUB');
    }

    public function test_saves_real_source_metadata_without_creating_tasks_and_suppresses_duplicate_imports(): void
    {
        $run = TaskExtraction::factory()->create(['status' => 'reading']);
        $this->connect(User::findOrFail($run->user_id));
        Http::fake(['agent.test/*' => Http::response($this->agentResult())]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonMissingPath('errors')
            ->assertJsonPath('data.candidateInbox.run.candidate_count', 1)->assertJsonPath('data.candidateInbox.candidates.0.due_date', null);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('task_candidates', ['message_id' => 'abc123', 'evidence' => 'Please review the change.', 'status' => 'pending']);
        TaskExtraction::factory()->create(['user_id' => $run->user_id, 'status' => 'reading', 'created_at' => now()->addSecond()]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonMissingPath('errors')->assertJsonPath('data.candidateInbox.run.duplicate_count', 1);
        $this->assertDatabaseCount('task_candidates', 1);
    }

    public function test_empty_results_and_mailbox_failure_are_visible_without_making_tasks(): void
    {
        $run = TaskExtraction::factory()->create(['status' => 'reading']);
        $this->connect(User::findOrFail($run->user_id));
        Http::fake(['agent.test/*' => Http::sequence()->push(['status' => 'succeeded', 'read_count' => 0, 'candidates' => []])
            ->push(['status' => 'failed', 'error' => 'Gmail の読み取り制限に達しました。'])]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonMissingPath('errors')->assertJsonPath('data.candidateInbox.run.status', 'succeeded')->assertJsonCount(0, 'data.candidateInbox.candidates');
        $run->refresh()->update(['status' => 'reading']);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonPath('data.candidateInbox.run.status', 'failed')
            ->assertJsonPath('data.candidateInbox.run.error', 'Gmail の読み取り制限に達しました。');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_invalid_agent_payload_is_rejected_atomically(): void
    {
        $run = TaskExtraction::factory()->create(['status' => 'reading']);
        $this->connect(User::findOrFail($run->user_id));
        $result = $this->agentResult();
        $result['candidates'][] = array_merge($result['candidates'][0], ['message_id' => '../bad']);
        Http::fake(['agent.test/*' => Http::response($result)]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonPath('data.candidateInbox.run.status', 'failed');
        $this->assertDatabaseCount('task_candidates', 0);
    }

    public function test_user_can_edit_and_confirm_candidate_exactly_once_with_source_preserved(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11));
        $candidate = TaskCandidate::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $candidate->user_id]);
        $this->connect($folder->user);
        $variables = ['id' => (string) $candidate->id, 'folder' => (string) $folder->id, 'input' => ['title' => '  編集したタスク  ', 'due_date' => '2026-11-18']];
        $first = $this->postJson('/graphql', ['query' => self::ACCEPT, 'variables' => $variables])->assertJsonMissingPath('errors')->assertJsonPath('data.acceptTaskCandidate.title', '編集したタスク');
        $this->postJson('/graphql', ['query' => self::ACCEPT, 'variables' => $variables])->assertJsonPath('data.acceptTaskCandidate.id', $first->json('data.acceptTaskCandidate.id'));
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('task_candidates', ['id' => $candidate->id, 'status' => 'accepted', 'task_id' => $first->json('data.acceptTaskCandidate.id'), 'evidence' => $candidate->evidence]);
        Http::assertNothingSent();
    }

    public function test_missing_deadline_does_not_silently_create_a_task(): void
    {
        $candidate = TaskCandidate::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $candidate->user_id]);
        $this->connect($folder->user);
        $this->postJson('/graphql', ['query' => self::ACCEPT, 'variables' => ['id' => (string) $candidate->id, 'folder' => (string) $folder->id,
            'input' => ['title' => $candidate->title, 'due_date' => '']]])->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('task_candidates', ['id' => $candidate->id, 'status' => 'pending']);
    }

    public function test_other_users_candidates_and_folders_cannot_be_used(): void
    {
        $candidate = TaskCandidate::factory()->create();
        $otherCandidate = TaskCandidate::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $candidate->user_id]);
        $foreignFolder = Folder::factory()->create();
        $this->connect($folder->user);
        foreach ([[$otherCandidate->id, $folder->id], [$candidate->id, $foreignFolder->id]] as [$id, $folderId]) {
            $this->postJson('/graphql', ['query' => self::ACCEPT, 'variables' => ['id' => (string) $id, 'folder' => (string) $folderId,
                'input' => ['title' => 'test', 'due_date' => '2027-01-01']]])->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
        }
        $this->postJson('/graphql', ['query' => 'mutation($id: ID!) { dismissTaskCandidate(id: $id) { id } }', 'variables' => ['id' => (string) $otherCandidate->id]])
            ->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_dismissal_persists_and_blocks_later_acceptance(): void
    {
        $candidate = TaskCandidate::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $candidate->user_id]);
        $this->connect($folder->user);
        $this->postJson('/graphql', ['query' => 'mutation($id: ID!) { dismissTaskCandidate(id: $id) { id status } }', 'variables' => ['id' => (string) $candidate->id]])
            ->assertJsonPath('data.dismissTaskCandidate.status', 'dismissed');
        $this->postJson('/graphql', ['query' => self::ACCEPT, 'variables' => ['id' => (string) $candidate->id, 'folder' => (string) $folder->id,
            'input' => ['title' => 'test', 'due_date' => '2027-01-01']]])->assertJsonPath('errors.0.extensions.code', 'CONFLICT');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_completed_background_results_are_recovered_when_user_returns_later(): void
    {
        $run = TaskExtraction::factory()->create(['status' => 'reading', 'created_at' => now()->subMinutes(10)]);
        $this->connect(User::findOrFail($run->user_id));
        Http::fake(['agent.test/*' => Http::response($this->agentResult())]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonMissingPath('errors')
            ->assertJsonPath('data.candidateInbox.run.status', 'succeeded')->assertJsonCount(1, 'data.candidateInbox.candidates');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_stalled_background_runs_expire_without_leaving_the_screen_busy(): void
    {
        $run = TaskExtraction::factory()->create(['status' => 'reading', 'created_at' => now()->subMinutes(10)]);
        $this->connect(User::findOrFail($run->user_id));
        Http::fake(['agent.test/*' => Http::response(['status' => 'reading'])]);
        $this->postJson('/graphql', ['query' => self::INBOX])->assertJsonMissingPath('errors')
            ->assertJsonPath('data.candidateInbox.run.status', 'failed');
        $this->assertDatabaseCount('task_candidates', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_busy_reader_is_not_reported_as_a_connection_failure(): void
    {
        $this->connect(User::factory()->create());
        Http::fake(['agent.test/*' => Http::response(['code' => 'BUSY', 'retry_after' => 3], 429)]);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: GITHUB) { status error } }'])
            ->assertJsonMissingPath('errors')->assertJsonPath('data.extractGmailTasks.status', 'failed')
            ->assertJsonPath('data.extractGmailTasks.error', '前の処理が続いているか、連続読み取りの待機時間中です。少し待ってから再試行してください。');
        $this->assertDatabaseCount('task_candidates', 0);
    }

    public function test_reader_network_failure_is_reported_as_unavailable(): void
    {
        $this->connect(User::factory()->create());
        Http::fake(['agent.test/*' => Http::failedConnection()]);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: GITHUB) { status error } }'])
            ->assertJsonPath('data.extractGmailTasks.status', 'failed')
            ->assertJsonPath('data.extractGmailTasks.error', 'メール読み取りサービスに接続できません。サービスの起動状態を確認してください。');
        $this->assertDatabaseCount('tasks', 0);
    }

    private function agentResult(): array
    {
        return ['status' => 'succeeded', 'read_count' => 1, 'candidates' => [[
            'message_id' => 'abc123', 'title' => 'Review task', 'description' => 'Check the review.', 'due_date' => null,
            'subject' => 'Project review', 'sender' => 'review@example.test', 'received_at' => '2026-11-11T12:00:00+09:00',
            'reason' => 'Review requested.', 'evidence' => 'Please review the change.',
        ]]];
    }
}
