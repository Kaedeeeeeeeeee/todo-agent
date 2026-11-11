<?php

namespace App\GraphQL;

use App\Http\Requests\CreateFolder;
use App\Http\Requests\CreateTask;
use App\Http\Requests\EditTask;
use App\Models\Folder;
use App\Models\Task;
use App\Models\User;
use GraphQL\Error\Error;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TaskOperations
{
    public function me(mixed $root, array $args, GraphQLContext $context): User
    {
        return $context->user();
    }

    public function folders(mixed $root, array $args, GraphQLContext $context): array
    {
        $this->validatePagination($args);
        $page = $context->user()->folders()->orderBy('id')
            ->paginate($args['first'], ['id', 'title'], 'page', $args['page']);

        return $this->page($page, array_map($this->serializeFolder(...), $page->items()));
    }

    public function createFolder(mixed $root, array $args, GraphQLContext $context): array
    {
        $request = new CreateFolder;
        $input = $this->validate(['title' => trim($args['input']['title'])], $request->rules(), $request->messages());
        $folder = new Folder;
        $folder->title = $input['title'];
        $context->user()->folders()->save($folder);

        return $this->serializeFolder($folder);
    }

    /** @return array{id: int, title: string, tasks_url: string} */
    private function serializeFolder(Folder $folder): array
    {
        return ['id' => $folder->id, 'title' => $folder->title, 'tasks_url' => route('tasks.index', ['folder' => $folder])];
    }

    public function tasks(mixed $root, array $args, GraphQLContext $context): array
    {
        $folder = $this->ownedFolder($context, $args['folder_id']);
        $this->validatePagination($args);
        $page = $folder->tasks()->when(isset($args['status']), fn ($query) => $query->where('status', $args['status']))
            ->orderBy('id')->paginate($args['first'], ['*'], 'page', $args['page']);

        return $this->page($page, array_map($this->serializeTask(...), $page->items()));
    }

    public function task(mixed $root, array $args, GraphQLContext $context): array
    {
        return $this->serializeTask($this->ownedTask($context, $args));
    }

    public function createTask(mixed $root, array $args, GraphQLContext $context): array
    {
        $folder = $this->ownedFolder($context, $args['folder_id']);
        $input = $this->validateInput($args['input'], new CreateTask);
        $task = new Task;
        $task->title = $input['title'];
        $task->due_date = $input['due_date'];
        $task->status = 1;
        $folder->tasks()->save($task);

        return $this->serializeTask($task);
    }

    public function updateTask(mixed $root, array $args, GraphQLContext $context): array
    {
        $task = $this->ownedTask($context, $args);
        $input = $this->validateInput($args['input'], new EditTask);
        $task->title = $input['title'];
        $task->due_date = $input['due_date'];
        $task->status = $input['status'];
        $task->save();

        return $this->serializeTask($task);
    }

    public function updateTaskStatus(mixed $root, array $args, GraphQLContext $context): array
    {
        $task = $this->ownedTask($context, $args);
        $task->status = $args['status'];
        $task->save();

        return $this->serializeTask($task);
    }

    /** @return array{id: int, deleted: bool} */
    public function deleteTask(mixed $root, array $args, GraphQLContext $context): array
    {
        $task = $this->ownedTask($context, $args);
        $this->validate($args, ['confirm' => ['accepted']], ['confirm.accepted' => '削除を確認してください。']);
        $id = $task->id;
        $task->delete();

        return ['id' => $id, 'deleted' => true];
    }

    private function ownedFolder(GraphQLContext $context, string $id): Folder
    {
        $folder = $context->user()->folders()->find($id);
        if (! $folder || Gate::forUser($context->user())->denies('view', $folder)) {
            throw new Error('フォルダが見つかりません。', extensions: ['code' => 'NOT_FOUND']);
        }

        return $folder;
    }

    private function ownedTask(GraphQLContext $context, array $args): Task
    {
        $task = $this->ownedFolder($context, $args['folder_id'])->tasks()->find($args['id']);
        if (! $task) {
            throw new Error('タスクが見つかりません。', extensions: ['code' => 'NOT_FOUND']);
        }

        return $task;
    }

    private function validateInput(array $input, CreateTask $request): array
    {
        $input['title'] = trim($input['title']);

        return $this->validate($input, $request->rules(), $request->messages());
    }

    private function validatePagination(array $args): void
    {
        $this->validate($args, [
            'page' => ['required', 'integer', 'min:1'],
            'first' => ['required', 'integer', 'between:1,50'],
        ], []);
    }

    private function validate(array $input, array $rules, array $messages): array
    {
        $validator = Validator::make($input, $rules, $messages);
        if ($validator->fails()) {
            throw new Error('入力内容を確認してください。', extensions: [
                'code' => 'VALIDATION_FAILED',
                'validation' => $validator->errors()->messages(),
            ]);
        }

        return $validator->validated();
    }

    /** @return array{id: int, folder_id: int, title: string, status: int, due_date: string, edit_url: string, delete_url: string} */
    public function serializeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'folder_id' => $task->folder_id,
            'title' => $task->title,
            'status' => $task->status,
            'due_date' => $task->due_date->format('Y-m-d'),
            'edit_url' => route('tasks.edit', ['folder' => $task->folder_id, 'task' => $task]),
            'delete_url' => route('tasks.delete', ['folder' => $task->folder_id, 'task' => $task]),
        ];
    }

    /** @return array{data: array, paginatorInfo: array{currentPage: int, lastPage: int, hasMorePages: bool}} */
    private function page(LengthAwarePaginator $page, array $data): array
    {
        return [
            'data' => $data,
            'paginatorInfo' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'hasMorePages' => $page->hasMorePages(),
            ],
        ];
    }
}
