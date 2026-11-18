<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class DailySourceScan
{
    public function send(TaskExtraction $run): void
    {
        try {
            DB::transaction(function () use ($run) {
                GmailConnection::where('user_id', $run->user_id)->lockForUpdate()->firstOrFail();
                if (! app(GmailConnectionManager::class)->canRead(User::findOrFail($run->user_id), $run->scope)) {
                    $run->update(['status' => 'failed', 'error' => 'メール接続が解除されたため、読み取りを中止しました。', 'completed_at' => now(), 'retry_at' => null]);

                    return;
                }
                $known = DB::table('gmail_processed_messages')->where('user_id', $run->user_id)->where('scope', $this->processedScope($run))
                    ->where('processed_at', '>=', now()->subDays(2))->pluck('message_id')->all();
                $known = array_values(array_unique(array_merge($known, TaskCandidate::where('user_id', $run->user_id)->pluck('message_id')->all())));
                $options = array_merge($run->scan_options, ['exclude_ids' => $known]);
                if ($run->stage === 'processing') {
                    $options['messages'] = array_slice($run->mail_plan, $run->scanned_count, 6);
                }
                $run->update(['batch_started_at' => now(), 'next_batch_at' => null]);
                app(GmailTaskAgent::class)->start($run->batch_id, $run->scope, $options, $run->stage === 'listing' ? 'list' : 'extract');
            });
        } catch (TaskAgentException $error) {
            if ($error->errorCode === 'BUSY') {
                $run->update(['next_batch_at' => now()->addSeconds(max(5, $error->retryAfter)), 'batch_started_at' => null]);
            } else {
                app(TaskExtractionManager::class)->fail($run, $error->getMessage());
            }
        }
    }

    public function refresh(TaskExtraction $run): void
    {
        $lock = Cache::lock('gmail-batch:'.$run->id, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $run->refresh();
            if (! in_array($run->status, ['reading', 'extracting'])) {
                return;
            }
            if ($run->next_batch_at) {
                if ($run->next_batch_at->lte(now())) {
                    $this->send($run);
                }

                return;
            }
            try {
                $result = app(GmailTaskAgent::class)->result($run->batch_id);
            } catch (TaskAgentException) {
                if ($run->batch_started_at?->lt(now()->subMinutes(5))) {
                    app(TaskExtractionManager::class)->fail($run, '結果を取得できませんでした。未完了の続きから再試行できます。');
                }

                return;
            }
            if (in_array($result['status'] ?? null, ['reading', 'extracting'])) {
                if ($run->batch_started_at?->lt(now()->subMinutes(5))) {
                    app(GmailTaskAgent::class)->cancel($run->batch_id);
                    app(TaskExtractionManager::class)->fail($run, '読み取りが時間切れになりました。未完了の続きから再試行できます。');
                } else {
                    $run->update(['status' => $result['status']]);
                }

                return;
            }
            if (($result['status'] ?? null) === 'failed') {
                app(TaskExtractionManager::class)->fail($run, mb_substr($result['error'] ?? '読み取りに失敗しました。続きから再試行してください。', 0, 500));

                return;
            }
            if (($result['status'] ?? null) !== 'succeeded') {
                throw new \RuntimeException('Invalid scan status');
            }
            DB::transaction(function () use ($run, $result) {
                GmailConnection::where('user_id', $run->user_id)->lockForUpdate()->firstOrFail();
                $locked = TaskExtraction::lockForUpdate()->findOrFail($run->id);
                if (! in_array($locked->status, ['reading', 'extracting']) || $locked->batch_id !== $run->batch_id) {
                    return;
                }
                if (! app(GmailConnectionManager::class)->canRead(User::findOrFail($run->user_id), $run->scope)) {
                    $locked->update(['status' => 'failed', 'error' => 'メール接続が解除されたため、結果は保存しませんでした。', 'completed_at' => now(), 'retry_at' => null]);

                    return;
                }
                if ($locked->stage === 'listing') {
                    $this->saveListing($locked, $result);
                } else {
                    $this->saveBatch($locked, $result);
                }
            });
            $run->refresh();
        } catch (Throwable) {
            app(TaskExtractionManager::class)->fail($run, '結果を検証できなかったため、このバッチは保存しませんでした。続きから再試行できます。');
        } finally {
            $lock->release();
        }
    }

    private function saveListing(TaskExtraction $run, array $result): void
    {
        $data = Validator::make($result, [
            'messages' => ['present', 'array', 'max:100'], 'messages.*.id' => ['required', 'regex:/^[a-f0-9]{1,64}$/', 'distinct'],
            'messages.*.subject' => ['present', 'string', 'max:2000'], 'messages.*.from_' => ['required', 'string', 'max:1000'],
            'messages.*.email_ts' => ['required', 'string', 'max:50'], 'messages.*.display_url' => ['required', 'string', 'max:2048'],
            'messages.*.source_type' => ['sometimes', 'required', 'in:GMAIL,CODEX'],
            'next_page_token' => ['present', 'nullable', 'string', 'max:4096'],
        ])->validate();
        foreach ($data['messages'] as &$message) {
            $type = $message['source_type'] ?? ($run->scope === 'CODEX' ? 'CODEX' : 'GMAIL');
            $allowed = $run->scope === 'ALL' ? ['GMAIL', 'CODEX'] : [$run->scope === 'CODEX' ? 'CODEX' : 'GMAIL'];
            if (! in_array($type, $allowed, true) || ($type === 'CODEX'
                ? ! preg_match('~^codex://threads/[a-f0-9-]{36}$~', $message['display_url'])
                : ! filter_var($message['display_url'], FILTER_VALIDATE_URL))) {
                throw new \RuntimeException('Unexpected source metadata');
            }
            $message['source_type'] = $type;
        }
        unset($message);
        $tokens = $run->listing_tokens ?? [];
        $next = $data['next_page_token'];
        if ($next && (in_array($next, $tokens, true) || $next === ($run->scan_options['page_token'] ?? null))) {
            throw new \RuntimeException('Repeated listing cursor');
        }
        $plan = collect(array_merge($run->mail_plan ?? [], $data['messages']))->unique('id')->values()->all();
        $options = $run->scan_options;
        if ($next) {
            $tokens[] = $next;
            $options['page_token'] = $next;
            $run->update(['mail_plan' => $plan, 'listing_tokens' => $tokens, 'scan_options' => $options]);
            $this->queueNext($run);
        } else {
            $options['page_token'] = null;
            $run->update(['mail_plan' => $plan, 'total_count' => count($plan), 'scan_options' => $options, 'stage' => count($plan) ? 'processing' : 'complete']);
            if (count($plan)) {
                $this->queueNext($run);
            } else {
                $this->complete($run);
            }
        }
    }

    private function saveBatch(TaskExtraction $run, array $result): void
    {
        $data = Validator::make($result, [
            'read_count' => ['required', 'integer', 'between:0,6'], 'skipped_count' => ['required', 'integer', 'between:0,6'],
            'processed_ids' => ['required', 'array', 'max:6'], 'processed_ids.*' => ['required', 'regex:/^[a-f0-9]{1,64}$/', 'distinct'],
            'candidates' => ['present', 'array', 'max:6'], 'candidates.*.message_id' => ['required', 'regex:/^[a-f0-9]{1,64}$/', 'distinct'],
            'candidates.*.title' => ['required', 'string', 'max:100'], 'candidates.*.description' => ['required', 'string', 'max:4000'],
            'candidates.*.reason' => ['required', 'string', 'max:1000'], 'candidates.*.evidence' => ['required', 'string', 'max:1000'],
            'candidates.*.subject' => ['required', 'string', 'max:2000'], 'candidates.*.sender' => ['required', 'string', 'max:1000'],
            'candidates.*.received_at' => ['required', 'string', 'max:50'], 'candidates.*.due_date' => ['present', 'nullable', 'date_format:Y-m-d'],
        ])->validate();
        $expected = array_column(array_slice($run->mail_plan, $run->scanned_count, 6), 'id');
        if (count($expected) !== count($data['processed_ids']) || array_diff($expected, $data['processed_ids'])
            || count($expected) !== $data['read_count'] + $data['skipped_count']
            || array_diff(array_column($data['candidates'], 'message_id'), $expected)) {
            throw new \RuntimeException('Unexpected batch messages');
        }
        $added = 0;
        foreach ($data['candidates'] as $candidate) {
            $source = collect($run->mail_plan)->firstWhere('id', $candidate['message_id']);
            $candidate['source_type'] = $source['source_type'] ?? ($run->scope === 'CODEX' ? 'CODEX' : 'GMAIL');
            $candidate['source_url'] = $candidate['source_type'] === 'CODEX' ? $source['display_url'] : null;

            $model = TaskCandidate::firstOrCreate(['user_id' => $run->user_id, 'message_id' => $candidate['message_id']],
                array_merge($candidate, ['user_id' => $run->user_id, 'task_extraction_id' => $run->id, 'status' => 'pending']));
            $added += (int) $model->wasRecentlyCreated;
        }
        foreach ($expected as $id) {
            DB::table('gmail_processed_messages')->insertOrIgnore(['user_id' => $run->user_id, 'scope' => $this->processedScope($run), 'message_id' => $id, 'processed_at' => now()]);
        }
        $run->update(['scanned_count' => $run->scanned_count + count($expected), 'batch_count' => $run->batch_count + 1,
            'read_count' => $run->read_count + $data['read_count'], 'candidate_count' => $run->candidate_count + $added,
            'skipped_count' => $run->skipped_count + $data['skipped_count'],
            'duplicate_count' => $run->duplicate_count + count($data['candidates']) - $added]);
        if ($run->scanned_count < $run->total_count) {
            $this->queueNext($run);
        } else {
            $this->complete($run);
        }
    }

    private function processedScope(TaskExtraction $run): string
    {
        $instructions = trim($run->scan_options['instructions'] ?? '');
        if (in_array($run->scope, ['MAIL', 'ALL', 'CODEX'])) {
            return 'GENERAL:'.substr(hash('sha256', $instructions), 0, 16);
        }

        return $run->scope.($instructions === '' ? '' : ':'.substr(hash('sha256', $instructions), 0, 16));
    }

    private function queueNext(TaskExtraction $run): void
    {
        $run->update(['status' => 'reading', 'has_more' => true, 'batch_id' => (string) Str::uuid(),
            'next_batch_at' => now()->max(($run->batch_started_at ?? now())->copy()->addMinute()), 'batch_started_at' => null]);
    }

    private function complete(TaskExtraction $run): void
    {
        $run->update(['status' => 'succeeded', 'stage' => 'complete', 'has_more' => false, 'completed_at' => now(), 'next_batch_at' => null, 'retry_at' => null, 'error' => null]);
        TaskExtraction::where('user_id', $run->user_id)->where('scope', $run->scope)->where('status', 'failed')->update(['retry_at' => null]);
    }
}
