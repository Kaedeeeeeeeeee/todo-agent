<?php

namespace App\GraphQL;

use App\Http\Requests\CreateTask;
use App\Models\Task;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Services\GmailConnectionManager;
use App\Services\GmailTaskAgent;
use App\Services\TaskAgentException;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Throwable;

class TaskCandidateOperations
{
    public function inbox(mixed $root, array $args, GraphQLContext $context): array
    {
        $enabled = $this->enabled($context);
        if (! $enabled) {
            return ['enabled' => false, 'account' => null, 'run' => null, 'candidates' => []];
        }
        $run = TaskExtraction::where('user_id', $context->user()->id)->latest()->first();
        if ($run && in_array($run->status, ['reading', 'extracting'])) {
            $this->refresh($run);
        }

        return ['enabled' => true, 'account' => config('services.task_agent.account'), 'run' => $run,
            'candidates' => TaskCandidate::where('user_id', $context->user()->id)->latest('id')->limit(50)->get()];
    }

    public function start(mixed $root, array $args, GraphQLContext $context): TaskExtraction
    {
        $this->authorize($context);
        $userId = $context->user()->id;
        $lock = Cache::lock('gmail-extraction:'.$userId, 15);
        if (! $lock->get()) {
            throw new Error('読み取りを開始中です。候補を再取得してください。', extensions: ['code' => 'BUSY']);
        }
        try {
            $this->authorize($context);
            $existing = TaskExtraction::where('user_id', $userId)->whereIn('status', ['reading', 'extracting'])->latest()->first();
            if ($existing) {
                $this->refresh($existing);
                if (in_array($existing->status, ['reading', 'extracting'])) {
                    return $existing;
                }
            }
            if (TaskExtraction::where('user_id', $userId)->where('created_at', '>', now()->subMinute())->exists()) {
                throw new Error('少し時間をおいて再試行してください。', extensions: ['code' => 'BUSY']);
            }
            $run = TaskExtraction::create(['user_id' => $userId, 'scope' => $args['scope'], 'status' => 'reading']);
            try {
                app(GmailTaskAgent::class)->start($run->id, $run->scope);
            } catch (TaskAgentException $error) {
                $run->update(['status' => 'failed', 'error' => $error->getMessage()]);
            }

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function accept(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->authorize($context);

        return DB::transaction(function () use ($args, $context) {
            $candidate = $this->candidate($args['id'], $context);
            $folder = $context->user()->folders()->find($args['folder_id']);
            if (! $folder) {
                throw new Error('フォルダが見つかりません。', extensions: ['code' => 'NOT_FOUND']);
            }
            if ($candidate->status === 'accepted') {
                $task = $folder->tasks()->find($candidate->task_id);
                if ($task) {
                    return (new TaskOperations)->serializeTask($task);
                }
                throw new Error('この候補は追加済みです。一覧を再取得してください。', extensions: ['code' => 'CONFLICT']);
            }
            if ($candidate->status !== 'pending') {
                throw new Error('この候補は見送り済みです。', extensions: ['code' => 'CONFLICT']);
            }
            $request = new CreateTask;
            $input = $args['input'];
            $input['title'] = trim($input['title']);
            $validator = Validator::make($input, $request->rules(), $request->messages());
            if ($validator->fails()) {
                throw new Error('入力内容を確認してください。', extensions: ['code' => 'VALIDATION_FAILED', 'validation' => $validator->errors()->messages()]);
            }
            $task = new Task;
            $task->title = $input['title'];
            $task->due_date = $input['due_date'];
            $task->status = 1;
            $folder->tasks()->save($task);
            $candidate->update(['status' => 'accepted', 'task_id' => $task->id, 'title' => $task->title, 'due_date' => $task->due_date]);

            return (new TaskOperations)->serializeTask($task);
        });
    }

    public function dismiss(mixed $root, array $args, GraphQLContext $context): TaskCandidate
    {
        $this->authorize($context);

        return DB::transaction(function () use ($args, $context) {
            $candidate = $this->candidate($args['id'], $context);
            if ($candidate->status === 'accepted') {
                throw new Error('この候補は追加済みです。', extensions: ['code' => 'CONFLICT']);
            }
            $candidate->update(['status' => 'dismissed']);

            return $candidate;
        });
    }

    private function candidate(string $id, GraphQLContext $context): TaskCandidate
    {
        $candidate = TaskCandidate::where('user_id', $context->user()->id)->lockForUpdate()->find($id);
        if (! $candidate) {
            throw new Error('候補が見つかりません。', extensions: ['code' => 'NOT_FOUND']);
        }

        return $candidate;
    }

    private function enabled(GraphQLContext $context): bool
    {
        return app(GmailConnectionManager::class)->connected($context->user());
    }

    private function authorize(GraphQLContext $context): void
    {
        if (! $this->enabled($context)) {
            throw new Error('このアカウントには Gmail が接続されていません。', extensions: ['code' => 'FORBIDDEN']);
        }
    }

    private function refresh(TaskExtraction $run): void
    {
        try {
            $result = app(GmailTaskAgent::class)->result($run->id);
            if (in_array($result['status'] ?? null, ['reading', 'extracting']) && $run->created_at->lt(now()->subMinutes(5))) {
                $result = ['status' => 'failed', 'error' => '読み取りが時間切れになりました。再試行してください。'];
            }
            if (! in_array($result['status'] ?? null, ['reading', 'extracting', 'succeeded', 'failed'])) {
                throw new \RuntimeException('Invalid agent status');
            }
            DB::transaction(function () use ($run, $result) {
                $locked = TaskExtraction::lockForUpdate()->findOrFail($run->id);
                if (! in_array($locked->status, ['reading', 'extracting'])) {
                    return;
                }
                if ($result['status'] === 'succeeded') {
                    $validated = Validator::make($result, [
                        'read_count' => ['required', 'integer', 'between:0,6'], 'candidates' => ['present', 'array', 'max:6'],
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
                    $added = 0;
                    foreach ($validated['candidates'] as $data) {
                        $data['user_id'] = $locked->user_id;
                        $data['task_extraction_id'] = $locked->id;
                        $data['status'] = 'pending';
                        $candidate = TaskCandidate::firstOrCreate(['user_id' => $locked->user_id, 'message_id' => $data['message_id']], $data);
                        $added += (int) $candidate->wasRecentlyCreated;
                    }
                    $locked->update(['status' => 'succeeded', 'read_count' => $validated['read_count'], 'candidate_count' => $added,
                        'duplicate_count' => count($validated['candidates']) - $added]);
                } else {
                    $locked->update(['status' => $result['status'], 'error' => $result['status'] === 'failed'
                        ? mb_substr($result['error'] ?? '候補を生成できませんでした。', 0, 500) : null]);
                }
            });
            $run->refresh();
        } catch (Throwable) {
            $run->update(['status' => 'failed', 'error' => '結果を取得できませんでした。接続を確認して再試行してください。']);
        }
    }
}
