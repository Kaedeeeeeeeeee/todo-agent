<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_hashes_password_logs_in_and_redirects_guests_only_pages(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', ['name' => '学習者', 'email' => 'Learner@example.test', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect(route('home'));
        $user = User::where('email', 'learner@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->get('/login')->assertRedirect(route('home'));
        $this->get('/register')->assertRedirect(route('home'));
    }

    public function test_register_rejects_missing_fields_duplicate_email_and_password_mismatch(): void
    {
        User::factory()->create(['email' => 'exists@example.test']);
        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password']);
        $this->post('/register', ['name' => 'test', 'email' => 'EXISTS@example.test', 'password' => 'long-password', 'password_confirmation' => 'different'])->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_login_rejects_wrong_password_and_logout_clears_authentication(): void
    {
        $user = User::factory()->create(['email' => 'user@example.test', 'password' => 'right-password']);
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'メールアドレスまたはパスワードが正しくありません。']);
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'right-password'])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'rate-limit@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'rate-limit@example.test', 'password' => 'wrong'])->assertStatus(429)->assertSee('しばらくお待ちください');
    }

    public function test_password_reset_mail_and_token_allow_one_password_change(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        Notification::fake();
        $this->get('/password/reset')->assertOk();
        $this->post('/password/email', ['email' => $user->email])->assertSessionHas('status');
        $token = '';
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token, $user): bool {
            $token = $notification->token;
            $mail = $notification->toMail($user);
            $this->assertSame('TaskList パスワード再設定', $mail->subject);
            $html = (string) $mail->render();
            $this->assertStringContainsString('新しいパスワードを設定する', $html);
            $this->assertStringContainsString($token, $html);

            return true;
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->post('/password/reset', $payload)->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->post('/password/reset', $payload)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_email_gets_generic_reset_response_without_notification(): void
    {
        Notification::fake();
        $this->post('/password/email', ['email' => 'unknown@example.test'])->assertSessionHas('status')->assertSessionHasNoErrors();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_expired_invalid_and_mismatched_reset_tokens_do_not_change_password(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'original-password']);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'mismatch'];
        $this->post('/password/reset', $payload)->assertSessionHasErrors('password');
        $payload['password_confirmation'] = 'new-password';
        $this->post('/password/reset', array_merge($payload, ['token' => 'invalid']))->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/password/reset', $payload)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }
}
