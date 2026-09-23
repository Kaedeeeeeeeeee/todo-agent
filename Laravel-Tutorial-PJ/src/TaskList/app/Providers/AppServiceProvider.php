<?php

namespace App\Providers;

use App\Models\Folder;
use App\Policies\FolderPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Folder::class, FolderPolicy::class);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('account:'.mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()),
        ]);
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            return (new MailMessage)
                ->subject('TaskList パスワード再設定')
                ->view('emails.reset-password', [
                    'url' => route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]),
                    'minutes' => config('auth.passwords.users.expire'),
                ]);
        });
    }
}
