<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaskExtractionManager
{
    public function start(User $user, string $scope, string $trigger = 'manual', ?TaskExtraction $previous = null): ?TaskExtraction
    {
        $lock = Cache::lock('gmail-extraction:'.$user->id, 30);
        if (! $lock->get()) {
            throw new Error('別の読み取り操作を処理しています。少し待ってから再試行してください。', extensions: ['code' => 'BUSY']);
        }
        try {
            if (! app(GmailConnectionManager::class)->connected($user)) {
                throw new Error('このアカウントには Gmail が接続されていません。', extensions: ['code' => 'FORBIDDEN']);
            }
            $connection = GmailConnection::where('user_id', $user->id)->firstOrFail();
            if ($trigger === 'scheduled' && (! $connection->schedule_enabled || (! $connection->next_run_at || $connection->next_run_at->gt(now())))) {
                return null;
            }
            if ($previous) {
                $previous->refresh();
                if ($previous->user_id !== $user->id || $previous->status !== 'failed') {
                    throw new Error('再試行できる読み取りが見つかりません。', extensions: ['code' => 'NOT_FOUND']);
                }
                if (TaskExtraction::where('user_id', $user->id)->where('scope', $scope)->where('status', 'succeeded')->where('created_at', '>', $previous->created_at)->exists()) {
                    throw new Error('新しい読み取りが完了しています。最新の候補を確認してください。', extensions: ['code' => 'CONFLICT']);
                }
                if (TaskExtraction::where('retry_of', $previous->id)->exists()) {
                    throw new Error('この読み取りは再試行済みです。実行履歴を再取得してください。', extensions: ['code' => 'CONFLICT']);
                }
                if ($trigger === 'retry' && (! $connection->schedule_enabled || (! $previous->retry_at || $previous->retry_at->gt(now())))) {
                    return null;
                }
            }
            $existing = TaskExtraction::where('user_id', $user->id)->whereIn('status', ['reading', 'extracting'])->latest()->first();
            if ($existing) {
                $this->refresh($existing);
                if (in_array($existing->status, ['reading', 'extracting'])) {
                    return $existing;
                }
            }
            if (TaskExtraction::where('user_id', $user->id)->where('created_at', '>', now()->subMinute())->exists()) {
                throw new Error('少し時間をおいて再試行してください。', extensions: ['code' => 'BUSY']);
            }
            $connection->refresh();
            $resume = $previous && $previous->stage !== 'legacy';
            $options = $resume ? $previous->scan_options : [
                'after' => now()->subDay()->timestamp,
                'before' => now()->timestamp,
                'page_token' => null,
            ];
            $run = DB::transaction(function () use ($user, $scope, $trigger, $previous, $options, $connection, $resume) {
                $run = TaskExtraction::create(['user_id' => $user->id, 'scope' => $scope, 'status' => 'reading',
                    'trigger' => $trigger, 'attempt' => $previous ? $previous->attempt + 1 : 1,
                    'retry_of' => $previous?->id, 'scan_options' => $options,
                    'stage' => $resume ? $previous->stage : 'listing', 'batch_id' => (string) Str::uuid(),
                    'mail_plan' => $resume ? $previous->mail_plan : [], 'listing_tokens' => $resume ? $previous->listing_tokens : [],
                    'total_count' => $resume ? $previous->total_count : null,
                    'scanned_count' => $resume ? $previous->scanned_count : 0, 'batch_count' => $resume ? $previous->batch_count : 0,
                    'read_count' => $resume ? $previous->read_count : 0, 'candidate_count' => $resume ? $previous->candidate_count : 0,
                    'duplicate_count' => $resume ? $previous->duplicate_count : 0, 'skipped_count' => $resume ? $previous->skipped_count : 0]);
                $previous?->update(['retry_at' => null]);
                if ($trigger === 'scheduled') {
                    $connection->update(['next_run_at' => app(GmailAutomation::class)->nextRun($connection->schedule_time)]);
                }

                return $run;
            });
            app(DailyMailScan::class)->send($run);

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function refresh(TaskExtraction $run): void
    {
        if ($run->stage !== 'legacy') {
            app(DailyMailScan::class)->refresh($run);

            return;
        }
        if (! in_array($run->status, ['reading', 'extracting'])) {
            return;
        }
        try {
            $result = app(GmailTaskAgent::class)->result($run->id);
            if (in_array($result['status'] ?? null, ['reading', 'extracting']) && $run->created_at->lt(now()->subMinutes(5))) {
                app(GmailTaskAgent::class)->cancel($run->id);
                $result = ['status' => 'failed', 'error' => '読み取りが時間切れになりました。再試行してください。'];
            }
            if (! in_array($result['status'] ?? null, ['reading', 'extracting', 'succeeded', 'failed'])) {
                throw new \RuntimeException('Invalid agent status');
            }
            DB::transaction(function () use ($run, $result) {
                $connection = GmailConnection::where('user_id', $run->user_id)->lockForUpdate()->first();
                $locked = TaskExtraction::lockForUpdate()->findOrFail($run->id);
                if (! in_array($locked->status, ['reading', 'extracting'])) {
                    return;
                }
                if (! $connection || ! app(GmailConnectionManager::class)->connected(User::findOrFail($run->user_id))) {
                    $locked->update(['status' => 'failed', 'error' => 'メール接続が解除されたため、結果は保存しませんでした。', 'completed_at' => now(), 'retry_at' => null]);

                    return;
                }
                if ($result['status'] === 'succeeded') {
                    $data = Validator::make($result, [
                        'read_count' => ['required', 'integer', 'between:0,6'], 'candidates' => ['present', 'array', 'max:6'],
                        'processed_ids' => ['sometimes', 'array', 'max:6'], 'processed_ids.*' => ['required', 'regex:/^[a-f0-9]{1,64}$/', 'distinct'],
                        'skipped_count' => ['sometimes', 'integer', 'between:0,6'],
                        'next_page_token' => ['sometimes', 'nullable', 'string', 'max:2048'],
                        'candidates.*.message_id' => ['required', 'regex:/^[a-f0-9]{1,64}$/', 'distinct'],
                        'candidates.*.title' => ['required', 'string', 'max:100'],
                        'candidates.*.description' => ['required', 'string', 'max:4000'],
                        'candidates.*.reason' => ['required', 'string', 'max:1000'],
                        'candidates.*.evidence' => ['required', 'string', 'max:1000'],
                        'candidates.*.subject' => ['required', 'string', 'max:2000'],
                        'candidates.*.sender' => ['required', 'string', 'max:1000'],
                        'candidates.*.received_at' => ['required', 'string', 'max:50'],
                        'candidates.*.due_date' => ['present', 'nullable', 'date_format:Y-m-d'],
                    ])->validate();
                    $ids = $data['processed_ids'] ?? array_column($data['candidates'], 'message_id');
                    $next = $data['next_page_token'] ?? null;
                    if (array_diff(array_column($data['candidates'], 'message_id'), $ids)
                        || ($next && $next === ($locked->scan_options['page_token'] ?? null))) {
                        throw new \RuntimeException('Invalid scan cursor');
                    }
                    $added = 0;
                    foreach ($data['candidates'] as $candidate) {
                        $candidate = TaskCandidate::firstOrCreate(['user_id' => $locked->user_id, 'message_id' => $candidate['message_id']],
                            array_merge($candidate, ['user_id' => $locked->user_id, 'task_extraction_id' => $locked->id, 'status' => 'pending']));
                        $added += (int) $candidate->wasRecentlyCreated;
                    }
                    foreach ($ids as $id) {
                        DB::table('gmail_processed_messages')->insertOrIgnore(['user_id' => $locked->user_id, 'scope' => $locked->scope, 'message_id' => $id, 'processed_at' => now()]);
                    }
                    if ($locked->scan_options) {
                        $state = $connection->scan_state ?? [];
                        $state[$locked->scope] = $next
                            ? array_merge($locked->scan_options, ['page_token' => $next])
                            : ['after' => max(0, $locked->scan_options['before'] - 1)];
                        $connection->update(['scan_state' => $state]);
                    }
                    $locked->update(['status' => 'succeeded', 'read_count' => $data['read_count'], 'candidate_count' => $added,
                        'duplicate_count' => count($data['candidates']) - $added, 'skipped_count' => $data['skipped_count'] ?? 0,
                        'completed_at' => now(), 'retry_at' => null, 'has_more' => (bool) $next, 'error' => null]);
                    TaskExtraction::where('user_id', $locked->user_id)->where('scope', $locked->scope)->where('status', 'failed')->update(['retry_at' => null]);
                } elseif ($result['status'] === 'failed') {
                    $this->fail($locked, mb_substr($result['error'] ?? '候補を生成できませんでした。再試行してください。', 0, 500));
                } else {
                    $locked->update(['status' => $result['status']]);
                }
            });
            $run->refresh();
        } catch (TaskAgentException) {
            if ($run->created_at->lt(now()->subMinutes(5))) {
                $this->fail($run, '結果を取得できませんでした。接続を確認して再試行してください。');
            }
        } catch (ValidationException|\RuntimeException $error) {
            $this->fail($run, '結果を検証できなかったため、候補を保存しませんでした。再試行してください。');
        } catch (Throwable) {
            // Transport interruptions are retried while the independent reader may still be working.
            if ($run->created_at->lt(now()->subMinutes(5))) {
                $this->fail($run, '結果を取得できませんでした。接続を確認して再試行してください。');
            }
        }
    }

    public function fail(TaskExtraction $run, string $message): void
    {
        $automatic = in_array($run->trigger, ['scheduled', 'retry']) && $run->attempt < 3
            && GmailConnection::where('user_id', $run->user_id)->where('schedule_enabled', true)->where('status', 'connected')->exists();
        $run->update(['status' => 'failed', 'error' => $message, 'completed_at' => now(),
            'retry_at' => $automatic ? now()->addMinutes($run->attempt * 2) : null]);
    }
}
