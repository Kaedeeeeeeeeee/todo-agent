<?php

namespace App\GraphQL;

use App\Http\Requests\CreateTask;
use App\Models\Task;
use App\Models\TaskCandidate;
use App\Models\TaskExtraction;
use App\Services\GmailConnectionManager;
use App\Services\TaskExtractionManager;
use GraphQL\Error\Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TaskCandidateOperations
{
    public function inbox(mixed $root, array $args, GraphQLContext $context): array
    {
        $user = $context->user();
        $access = app(GmailConnectionManager::class);
        $history = $access->historyEligible($user);
        $gmail = $access->connected($user);
        $sources = array_merge($history ? ['CODEX'] : [], $gmail ? ['GMAIL'] : []);
        if (! $sources) {
            return ['enabled' => false, 'codex_available' => false, 'account' => null, 'run' => null, 'latest_run' => null, 'candidates' => []];
        }
        $scopes = array_merge($history ? ['CODEX'] : [], $gmail ? ['GITHUB', 'BIZMATES', 'MAIL'] : [], $gmail && $history ? ['ALL'] : []);
        $runs = TaskExtraction::where('user_id', $user->id)->whereIn('scope', $scopes)->latest()->orderByDesc('id');
        $run = (clone $runs)->first();
        if ($run && in_array($run->status, ['reading', 'extracting'])) {
            app(TaskExtractionManager::class)->refresh($run);
        }
        $latest = isset($args['scope']) ? (clone $runs)->where('scope', $args['scope'])->first() : $run;
        if ($latest && $latest->id !== $run?->id && in_array($latest->status, ['reading', 'extracting'])) {
            app(TaskExtractionManager::class)->refresh($latest);
        }
        $query = TaskCandidate::where('user_id', $user->id)->whereIn('source_type', $sources)
            ->whereIn('task_extraction_id', $latest ? $this->candidateRunIds($latest) : []);

        return ['enabled' => true, 'codex_available' => $history, 'account' => $gmail ? config('services.task_agent.account') : null, 'run' => $run, 'latest_run' => $latest,
            'candidates' => $query->latest('id')->get()];
    }

    /** @return list<string> */
    private function candidateRunIds(TaskExtraction $run): array
    {
        $ids = [$run->id];
        while ($run->stage !== 'legacy' && $run->retry_of && ! in_array($run->retry_of, $ids, true)) {
            $previous = TaskExtraction::where('user_id', $run->user_id)->where('scope', $run->scope)
                ->where('stage', '!=', 'legacy')->find($run->retry_of);
            if (! $previous) {
                break;
            }
            $ids[] = $previous->id;
            $run = $previous;
        }

        return $ids;
    }

    public function start(mixed $root, array $args, GraphQLContext $context): TaskExtraction
    {
        return app(TaskExtractionManager::class)->start($context->user(), $args['scope'], instructions: $args['instructions'] ?? '');
    }

    public function accept(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->authorize($context);

        return DB::transaction(function () use ($args, $context) {
            $candidate = $this->candidate($args['id'], $context);
            if (! app(GmailConnectionManager::class)->canRead($context->user(), $candidate->source_type === 'CODEX' ? 'CODEX' : 'GITHUB')) {
                throw new Error('この読み取り元は接続されていません。', extensions: ['code' => 'FORBIDDEN']);
            }
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
            if (! app(GmailConnectionManager::class)->canRead($context->user(), $candidate->source_type === 'CODEX' ? 'CODEX' : 'GITHUB')) {
                throw new Error('この読み取り元は接続されていません。', extensions: ['code' => 'FORBIDDEN']);
            }
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
        return app(GmailConnectionManager::class)->connected($context->user()) || app(GmailConnectionManager::class)->historyEligible($context->user());
    }

    private function authorize(GraphQLContext $context): void
    {
        if (! $this->enabled($context)) {
            throw new Error('このアカウントには Gmail が接続されていません。', extensions: ['code' => 'FORBIDDEN']);
        }
    }
}
