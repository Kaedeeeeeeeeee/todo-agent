<?php

namespace Tests\Feature;

use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailConnectionGraphQLTest extends TestCase
{
    use RefreshDatabase;

    private const STATE = '{ gmailConnection { can_manage status account verified_at error service_status retry_after } candidateInbox { enabled candidates { id } } }';

    private const CONNECT = 'mutation { connectGmail { status account error service_status } }';

    private const DISCONNECT = 'mutation($confirm: Boolean!) { disconnectGmail(confirm: $confirm) { status account } }';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.task_agent.url' => 'http://agent.test', 'services.task_agent.token' => str_repeat('a', 64), 'services.task_agent.account' => 'owner@example.test']);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        config(['services.task_agent.owner_id' => $user->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_requires_login_and_never_exposes_the_local_mailbox_to_another_user(): void
    {
        $this->postJson('/graphql', ['query' => self::STATE])->assertUnauthorized();
        $owner = User::factory()->create();
        config(['services.task_agent.owner_id' => $owner->id]);
        GmailConnection::factory()->create(['user_id' => $owner->id]);
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::STATE])
            ->assertJsonPath('data.gmailConnection.status', 'unavailable')->assertJsonPath('data.gmailConnection.account', null)
            ->assertJsonPath('data.gmailConnection.can_manage', false)->assertJsonPath('data.candidateInbox.enabled', false);
        $this->postJson('/graphql', ['query' => self::CONNECT])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        $this->postJson('/graphql', ['query' => self::DISCONNECT, 'variables' => ['confirm' => true]])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        Http::assertNothingSent();
    }

    public function test_unbound_owner_sees_a_real_connection_entry_but_cannot_read_mail(): void
    {
        $this->owner();
        Http::fake(['agent.test/health' => Http::response(['available' => true, 'busy' => false, 'retry_after' => 0])]);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonMissingPath('errors')
            ->assertJsonPath('data.gmailConnection.status', 'disconnected')->assertJsonPath('data.gmailConnection.can_manage', true)
            ->assertJsonPath('data.gmailConnection.service_status', 'ready')->assertJsonPath('data.candidateInbox.enabled', false);
        $this->postJson('/graphql', ['query' => 'mutation { extractGmailTasks(scope: GITHUB) { id } }'])->assertJsonPath('errors.0.extensions.code', 'FORBIDDEN');
        Http::assertSentCount(1);
    }

    public function test_connect_verifies_the_live_account_before_granting_mail_access(): void
    {
        $owner = $this->owner();
        Http::fake([
            'agent.test/health' => Http::response(['available' => true, 'busy' => false, 'retry_after' => 0]),
            'agent.test/runs/*' => Http::sequence()->push(['status' => 'reading'], 202)->push(['status' => 'reading'])
                ->push(['status' => 'succeeded', 'account' => 'owner@example.test']),
        ]);
        $this->postJson('/graphql', ['query' => self::CONNECT])->assertJsonMissingPath('errors')->assertJsonPath('data.connectGmail.status', 'verifying');
        $this->assertDatabaseHas('gmail_connections', ['user_id' => $owner->id, 'status' => 'verifying']);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonMissingPath('errors')->assertJsonPath('data.gmailConnection.status', 'connected')
            ->assertJsonPath('data.gmailConnection.account', 'owner@example.test')->assertJsonPath('data.candidateInbox.enabled', true);
        $this->assertNotNull(GmailConnection::first()->verified_at);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['kind'] === 'verify');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_mismatched_gmail_account_is_rejected_without_exposing_candidates(): void
    {
        $owner = $this->owner();
        GmailConnection::factory()->create(['user_id' => $owner->id, 'status' => 'verifying', 'verification_id' => fake()->uuid()]);
        Http::fake(['agent.test/health' => Http::response(['busy' => false, 'retry_after' => 0]),
            'agent.test/runs/*' => Http::response(['status' => 'succeeded', 'account' => 'other@example.test'])]);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.gmailConnection.status', 'failed')
            ->assertJsonPath('data.candidateInbox.enabled', false);
        $this->assertDatabaseMissing('gmail_connections', ['status' => 'connected']);
    }

    public function test_busy_and_offline_reader_states_are_distinct(): void
    {
        $this->owner();
        Http::fake(['agent.test/health' => Http::sequence()->push(['busy' => true, 'retry_after' => 5])->push(['busy' => false, 'retry_after' => 2])
            ->pushResponse(Http::failedConnection())]);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.gmailConnection.service_status', 'busy')->assertJsonPath('data.gmailConnection.retry_after', 5);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.gmailConnection.service_status', 'cooldown')->assertJsonPath('data.gmailConnection.retry_after', 2);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.gmailConnection.service_status', 'offline');
    }

    public function test_disconnect_requires_confirmation_preserves_data_and_cancels_active_reads(): void
    {
        $owner = $this->owner();
        $connection = GmailConnection::factory()->create(['user_id' => $owner->id]);
        $run = TaskExtraction::factory()->create(['user_id' => $owner->id, 'status' => 'reading']);
        $candidate = TaskCandidate::factory()->create(['user_id' => $owner->id]);
        Http::fake(['agent.test/health' => Http::response(['busy' => false, 'retry_after' => 0]), 'agent.test/runs/*' => Http::response(['cancelled' => true])]);
        $this->postJson('/graphql', ['query' => self::DISCONNECT, 'variables' => ['confirm' => false]])->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->assertSame('connected', $connection->fresh()->status);
        $this->postJson('/graphql', ['query' => self::DISCONNECT, 'variables' => ['confirm' => true]])->assertJsonMissingPath('errors')->assertJsonPath('data.disconnectGmail.status', 'disconnected');
        $this->assertDatabaseHas('task_extractions', ['id' => $run->id, 'status' => 'failed']);
        $this->assertDatabaseHas('task_candidates', ['id' => $candidate->id]);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.candidateInbox.enabled', false)->assertJsonCount(0, 'data.candidateInbox.candidates');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), $run->id));
    }

    public function test_disconnect_still_revokes_access_when_reader_is_down(): void
    {
        $owner = $this->owner();
        GmailConnection::factory()->create(['user_id' => $owner->id]);
        TaskExtraction::factory()->create(['user_id' => $owner->id, 'status' => 'reading']);
        Http::fake(['agent.test/*' => Http::failedConnection()]);
        $this->postJson('/graphql', ['query' => self::DISCONNECT, 'variables' => ['confirm' => true]])->assertJsonMissingPath('errors')->assertJsonPath('data.disconnectGmail.status', 'disconnected');
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.candidateInbox.enabled', false);
    }

    public function test_disconnect_during_verification_cannot_be_undone_by_a_late_result(): void
    {
        $owner = $this->owner();
        $connection = GmailConnection::factory()->create(['user_id' => $owner->id, 'status' => 'verifying', 'verification_id' => fake()->uuid()]);
        Http::fake(['agent.test/health' => Http::response(['busy' => false, 'retry_after' => 0]), 'agent.test/runs/*' => Http::response(['cancelled' => true])]);
        $this->postJson('/graphql', ['query' => self::DISCONNECT, 'variables' => ['confirm' => true]])->assertJsonPath('data.disconnectGmail.status', 'disconnected');
        $this->assertNull($connection->fresh()->verification_id);
        $this->postJson('/graphql', ['query' => self::STATE])->assertJsonPath('data.gmailConnection.status', 'disconnected');
        Http::assertSentCount(3);
    }
}
