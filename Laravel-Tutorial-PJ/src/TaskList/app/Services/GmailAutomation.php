<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\TaskExtraction;
use App\Models\User;
use Carbon\Carbon;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class GmailAutomation
{
    public function nextRun(string $time): Carbon
    {
        $next = now('Asia/Tokyo')->setTimeFromTimeString($time.':00');
        if ($next->lessThanOrEqualTo(now())) {
            $next->addDay();
        }

        return $next->setTimezone(config('app.timezone'));
    }

    public function state(User $user): array
    {
        $connection = app(GmailConnectionManager::class)->eligible($user)
            ? GmailConnection::where('user_id', $user->id)->first() : null;
        $heartbeat = Cache::get('gmail-automation-heartbeat');
        $last = $connection ? TaskExtraction::where('user_id', $user->id)->latest()->first() : null;

        return ['enabled' => (bool) $connection?->schedule_enabled, 'time' => $connection?->schedule_time ?? '09:00',
            'scope' => $connection?->schedule_scope ?? 'BIZMATES', 'timezone' => 'Asia/Tokyo',
            'next_run_at' => $connection?->next_run_at?->toIso8601String(),
            'worker_online' => $heartbeat && Carbon::parse($heartbeat)->gt(now()->subSeconds(90)),
            'last_run_at' => $last?->created_at?->toIso8601String()];
    }

    public function save(User $user, array $input): array
    {
        if (! app(GmailConnectionManager::class)->connected($user)) {
            throw new Error('Gmail を接続してから自動読み取りを設定してください。', extensions: ['code' => 'FORBIDDEN']);
        }
        $validator = Validator::make($input, ['enabled' => ['required', 'boolean'], 'time' => ['required', 'date_format:H:i'], 'scope' => ['required', 'in:BIZMATES,GITHUB']]);
        if ($validator->fails()) {
            throw new Error('実行時刻と読み取り範囲を確認してください。', extensions: ['code' => 'VALIDATION_FAILED', 'validation' => $validator->errors()->messages()]);
        }
        $lock = Cache::lock('gmail-extraction:'.$user->id, 30);
        if (! $lock->get()) {
            throw new Error('読み取りを処理中です。少し待って保存してください。', extensions: ['code' => 'BUSY']);
        }
        try {
            if (! app(GmailConnectionManager::class)->connected($user)) {
                throw new Error('Gmail を再接続してください。', extensions: ['code' => 'FORBIDDEN']);
            }
            $connection = GmailConnection::where('user_id', $user->id)->firstOrFail();
            $unchanged = $connection->schedule_enabled && $input['enabled'] && $connection->schedule_time === $input['time'] && $connection->schedule_scope === $input['scope'];
            $connection->update(['schedule_enabled' => $input['enabled'], 'schedule_time' => $input['time'], 'schedule_scope' => $input['scope'],
                'next_run_at' => $input['enabled'] ? ($unchanged ? $connection->next_run_at : $this->nextRun($input['time'])) : null]);
            if (! $input['enabled']) {
                TaskExtraction::where('user_id', $user->id)->whereNotNull('retry_at')->update(['retry_at' => null]);
            }

            return $this->state($user);
        } finally {
            $lock->release();
        }
    }

    public function tick(): void
    {
        $lock = Cache::lock('gmail-automation-tick', 60);
        if (! $lock->get()) {
            return;
        }
        try {
            Cache::put('gmail-automation-heartbeat', now()->toIso8601String(), now()->addMinutes(3));
            $manager = app(TaskExtractionManager::class);
            foreach (TaskExtraction::whereIn('status', ['reading', 'extracting'])->get() as $run) {
                $manager->refresh($run);
            }
            foreach (TaskExtraction::where('status', 'failed')->where('retry_at', '<=', now())->get() as $run) {
                $user = User::find($run->user_id);
                if ($user && app(GmailConnectionManager::class)->connected($user)) {
                    try {
                        $manager->start($user, $run->scope, 'retry', $run);
                    } catch (Error) {
                        // Busy readers leave their retry pending for the next tick.
                    }
                } else {
                    $run->update(['retry_at' => null]);
                }
            }
            foreach (GmailConnection::where('schedule_enabled', true)->where('next_run_at', '<=', now())->get() as $connection) {
                $user = User::find($connection->user_id);
                if ($user && app(GmailConnectionManager::class)->connected($user)) {
                    try {
                        $manager->start($user, $connection->schedule_scope, 'scheduled');
                    } catch (Error) {
                        // Manual reads and scheduled reads share the same exclusion lock.
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }
}
