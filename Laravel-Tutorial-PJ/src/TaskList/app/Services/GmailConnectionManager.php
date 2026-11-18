<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\TaskExtraction;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GmailConnectionManager
{
    public function eligible(User $user): bool
    {
        return (string) $user->id === (string) config('services.task_agent.owner_id')
            && filled(config('services.task_agent.owner_id')) && filled(config('services.task_agent.token'))
            && filled(config('services.task_agent.account'));
    }

    public function historyEligible(User $user): bool
    {
        return filled(config('services.task_agent.owner_id')) && filled(config('services.task_agent.token'))
            && (string) $user->id === (string) config('services.task_agent.owner_id');
    }

    public function canRead(User $user, string $scope): bool
    {
        return match ($scope) {
            'CODEX' => $this->historyEligible($user),
            'ALL' => $this->historyEligible($user) && $this->connected($user),
            'MAIL', 'GITHUB', 'BIZMATES' => $this->connected($user),
            default => false,
        };
    }

    public function connected(User $user): bool
    {
        return $this->eligible($user) && GmailConnection::where('user_id', $user->id)->where('status', 'connected')
            ->where('account', strtolower(config('services.task_agent.account')))->exists();
    }

    public function state(User $user): array
    {
        if (! $this->eligible($user) && ! $this->historyEligible($user)) {
            return ['can_manage' => false, 'status' => 'unavailable', 'account' => null, 'verified_at' => null,
                'error' => null, 'service_status' => 'unavailable', 'retry_after' => 0];
        }
        $connection = GmailConnection::where('user_id', $user->id)->first();
        if ($connection?->status === 'connected' && $connection->account !== strtolower(config('services.task_agent.account'))) {
            $connection->update(['status' => 'failed', 'error' => '接続先が変更されています。Gmail を再接続してください。']);
        }
        if ($connection?->status === 'verifying') {
            $this->refresh($connection);
        }
        try {
            $health = app(GmailTaskAgent::class)->health();
            $retryAfter = max(0, (int) ($health['retry_after'] ?? 0));
            $serviceStatus = ($health['busy'] ?? false) ? 'busy' : ($retryAfter > 0 ? 'cooldown' : 'ready');
        } catch (TaskAgentException) {
            $serviceStatus = 'offline';
            $retryAfter = 0;
        }

        return ['can_manage' => $this->eligible($user), 'status' => $this->eligible($user) ? ($connection?->status ?? 'disconnected') : 'unavailable',
            'account' => $this->eligible($user) ? ($connection?->account ?? strtolower(config('services.task_agent.account'))) : null,
            'verified_at' => $connection?->verified_at?->toIso8601String(), 'error' => $connection?->error,
            'service_status' => $serviceStatus, 'retry_after' => $retryAfter];
    }

    public function connect(User $user): array
    {
        $this->authorize($user);
        $lock = Cache::lock('gmail-extraction:'.$user->id, 15);
        if (! $lock->get()) {
            throw new Error('別の操作を処理しています。少し待ってから再試行してください。', extensions: ['code' => 'BUSY']);
        }
        try {
            $connection = GmailConnection::firstOrCreate(['user_id' => $user->id]);
            if ($this->connected($user) || $connection->status === 'verifying') {
                return $this->state($user);
            }
            $connection->update(['status' => 'verifying', 'verification_id' => (string) Str::uuid(), 'error' => null, 'verified_at' => null]);
            try {
                app(GmailTaskAgent::class)->verify($connection->verification_id);
            } catch (TaskAgentException $error) {
                $connection->update(['status' => 'failed', 'error' => $error->getMessage()]);
            }

            return $this->state($user);
        } finally {
            $lock->release();
        }
    }

    public function disconnect(User $user, bool $confirm): array
    {
        $this->authorize($user);
        if (! $confirm) {
            throw new Error('接続解除を確認してください。', extensions: ['code' => 'VALIDATION_FAILED']);
        }
        $lock = Cache::lock('gmail-extraction:'.$user->id, 15);
        if (! $lock->get()) {
            throw new Error('別の操作を処理しています。少し待ってから再試行してください。', extensions: ['code' => 'BUSY']);
        }
        try {
            $ids = DB::transaction(function () use ($user) {
                $connection = GmailConnection::where('user_id', $user->id)->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
                $ids = TaskExtraction::where('user_id', $user->id)->where('scope', '!=', 'CODEX')->whereIn('status', ['reading', 'extracting'])->get()->map(fn ($run) => $run->batch_id ?? $run->id)->all();
                if ($connection->status === 'verifying' && $connection->verification_id) {
                    $ids[] = $connection->verification_id;
                }
                $connection->update(['status' => 'disconnected', 'verification_id' => null, 'verified_at' => null, 'error' => null, 'schedule_enabled' => $connection->schedule_scope === 'CODEX' && $connection->schedule_enabled, 'next_run_at' => $connection->schedule_scope === 'CODEX' ? $connection->next_run_at : null]);
                TaskExtraction::where('user_id', $user->id)->where('scope', '!=', 'CODEX')->whereIn('status', ['reading', 'extracting'])
                    ->update(['status' => 'failed', 'error' => 'メール接続を解除したため、読み取りを中止しました。', 'completed_at' => now(), 'retry_at' => null]);
                TaskExtraction::where('user_id', $user->id)->where('scope', '!=', 'CODEX')->whereNotNull('retry_at')->update(['retry_at' => null]);

                return $ids;
            });
            foreach ($ids as $id) {
                try {
                    app(GmailTaskAgent::class)->cancel($id);
                } catch (TaskAgentException) {
                    // Local access stays revoked even when the reader is unavailable.
                }
            }

            return $this->state($user);
        } finally {
            $lock->release();
        }
    }

    private function authorize(User $user): void
    {
        if (! $this->eligible($user)) {
            throw new Error('このアカウントでは本機の Gmail 接続を利用できません。', extensions: ['code' => 'FORBIDDEN']);
        }
    }

    private function refresh(GmailConnection $connection): void
    {
        $verificationId = $connection->verification_id;
        try {
            $result = app(GmailTaskAgent::class)->result($verificationId);
            if (($result['status'] ?? null) === 'succeeded') {
                $account = strtolower($result['account'] ?? '');
                if (! filter_var($account, FILTER_VALIDATE_EMAIL) || $account !== strtolower(config('services.task_agent.account'))) {
                    $update = ['status' => 'failed', 'error' => 'Codex の接続先が予定のメールアドレスと一致しません。Codex の Gmail 接続を確認してください。'];
                } else {
                    $update = ['status' => 'connected', 'account' => $account, 'verified_at' => now(), 'error' => null];
                }
            } elseif (($result['status'] ?? null) === 'failed' || $connection->updated_at->lt(now()->subMinutes(5))) {
                $update = ['status' => 'failed', 'error' => 'Gmail の接続を確認できませんでした。Codex の Gmail 接続を確認して再試行してください。'];
            } else {
                return;
            }
        } catch (Throwable) {
            $update = ['status' => 'failed', 'error' => 'メール読み取りサービスに接続できません。サービスの起動状態を確認してください。'];
        }
        GmailConnection::whereKey($connection->id)->where('status', 'verifying')->where('verification_id', $verificationId)->update($update);
        $connection->refresh();
    }
}
