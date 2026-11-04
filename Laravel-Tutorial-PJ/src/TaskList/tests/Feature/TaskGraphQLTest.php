<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TaskGraphQLTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = 'mutation Create($folder: ID!, $input: CreateTaskInput!) { createTask(folder_id: $folder, input: $input) { id folder_id title status due_date edit_url delete_url } }';

    private const STATUS = 'mutation Status($folder: ID!, $id: ID!, $status: TaskStatus!) { updateTaskStatus(folder_id: $folder, id: $id, status: $status) { id title status due_date } }';

    private const UPDATE = 'mutation Update($folder: ID!, $id: ID!, $input: UpdateTaskInput!) { updateTask(folder_id: $folder, id: $id, input: $input) { id title status due_date } }';

    #[TestWith(['query { me { id } }'])]
    #[TestWith(['mutation { createTask(folder_id: "1", input: {title: "test", due_date: "2026-11-06"}) { id } }'])]
    public function test_returns_401_for_unauthenticated_graphql_requests(string $query): void
    {
        $this->postJson('/graphql', ['query' => $query])->assertUnauthorized();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_returns_only_the_current_user_and_their_folders(): void
    {
        $folder = Folder::factory()->create();
        Folder::factory()->create(['title' => 'private folder']);

        $this->actingAs($folder->user)->postJson('/graphql', [
            'query' => '{ me { id name email } folders { data { id title } paginatorInfo { hasMorePages } } }',
        ])->assertOk()->assertJsonMissingPath('errors')
            ->assertJsonPath('data.me.id', (string) $folder->user_id)
            ->assertJsonCount(1, 'data.folders.data')
            ->assertJsonPath('data.folders.data.0.id', (string) $folder->id)
            ->assertJsonPath('data.folders.paginatorInfo.hasMorePages', false);
    }

    public function test_task_query_is_paginated_and_status_filter_is_applied(): void
    {
        $folder = Folder::factory()->create();
        $tasks = Task::factory()->count(3)->for($folder)->sequence(['status' => 1], ['status' => 2], ['status' => 3])->create();
        Task::factory()->create(['title' => 'secret task']);
        $this->actingAs($folder->user);

        $this->postJson('/graphql', [
            'query' => 'query($folder: ID!) { tasks(folder_id: $folder, first: 2) { data { id status } paginatorInfo { currentPage lastPage hasMorePages } } }',
            'variables' => ['folder' => (string) $folder->id],
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonCount(2, 'data.tasks.data')
            ->assertJsonPath('data.tasks.paginatorInfo', ['currentPage' => 1, 'lastPage' => 2, 'hasMorePages' => true]);

        $this->postJson('/graphql', [
            'query' => 'query($folder: ID!) { tasks(folder_id: $folder, first: 2, page: 2) { data { id status } paginatorInfo { hasMorePages } } }',
            'variables' => ['folder' => (string) $folder->id],
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonCount(1, 'data.tasks.data')
            ->assertJsonPath('data.tasks.data.0.id', (string) $tasks[2]->id)
            ->assertJsonPath('data.tasks.paginatorInfo.hasMorePages', false);

        $this->postJson('/graphql', [
            'query' => 'query($folder: ID!) { tasks(folder_id: $folder, status: DOING) { data { id status } } }',
            'variables' => ['folder' => (string) $folder->id],
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonCount(1, 'data.tasks.data')
            ->assertJsonPath('data.tasks.data.0.status', 'DOING');
    }

    #[TestWith([0, 20, 'page'])]
    #[TestWith([1, 51, 'first'])]
    #[TestWith([1, null, 'first'])]
    public function test_rejects_invalid_pagination_without_exposing_tasks(int $page, ?int $first, string $field): void
    {
        $folder = Folder::factory()->create();

        $this->actingAs($folder->user)->postJson('/graphql', [
            'query' => 'query($folder: ID!, $page: Int, $first: Int) { tasks(folder_id: $folder, page: $page, first: $first) { data { id } } }',
            'variables' => ['folder' => (string) $folder->id, 'page' => $page, 'first' => $first],
        ])->assertOk()->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => [['extensions' => ['validation' => [$field]]]]]);
    }

    public function test_creates_a_task_in_the_owners_folder_and_returns_saved_values(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'Asia/Tokyo'));
        $folder = Folder::factory()->create();

        $response = $this->actingAs($folder->user)->postJson('/graphql', [
            'query' => self::CREATE,
            'variables' => ['folder' => (string) $folder->id, 'input' => ['title' => '  API task  ', 'due_date' => '2026-11-04']],
        ])->assertOk()->assertJsonMissingPath('errors')
            ->assertJsonPath('data.createTask.title', 'API task')
            ->assertJsonPath('data.createTask.folder_id', (string) $folder->id)
            ->assertJsonPath('data.createTask.status', 'TODO')
            ->assertJsonPath('data.createTask.due_date', '2026-11-04');

        $id = $response->json('data.createTask.id');
        $this->assertDatabaseHas('tasks', ['id' => $id, 'folder_id' => $folder->id, 'title' => 'API task', 'status' => 1]);
        $this->assertSame('2026-11-04', Task::findOrFail($id)->due_date->toDateString());
        $response->assertJsonPath('data.createTask.edit_url', route('tasks.edit', ['folder' => $folder, 'task' => $id]));
    }

    #[TestWith(['title', '   ', 'タスク名を入力してください。'])]
    #[TestWith(['due_date', '', '期限を入力してください。'])]
    #[TestWith(['due_date', '2026-11-03', '期限は今日以降の日付にしてください。'])]
    #[TestWith(['due_date', '2026-02-30', '期限は正しい日付で入力してください。'])]
    public function test_invalid_creation_returns_field_errors_without_writing(string $field, string $value, string $message): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'Asia/Tokyo'));
        $folder = Folder::factory()->create();
        $input = array_replace(['title' => 'task', 'due_date' => '2026-11-06'], [$field => $value]);

        $this->actingAs($folder->user)->postJson('/graphql', [
            'query' => self::CREATE,
            'variables' => ['folder' => (string) $folder->id, 'input' => $input],
        ])->assertOk()->assertJsonPath('data.createTask', null)
            ->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED')
            ->assertJsonPath("errors.0.extensions.validation.{$field}.0", $message);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_rejects_overlong_titles_and_unexpected_ownership_fields(): void
    {
        $this->freezeTime();
        $folder = Folder::factory()->create();
        $input = ['title' => str_repeat('あ', 101), 'due_date' => today()->toDateString()];
        $this->actingAs($folder->user);

        $this->postJson('/graphql', ['query' => self::CREATE, 'variables' => ['folder' => (string) $folder->id, 'input' => $input]])
            ->assertOk()->assertJsonPath('errors.0.extensions.validation.title.0', 'タスク名は100文字以内で入力してください。');
        $input = ['title' => 'injected', 'due_date' => today()->toDateString(), 'user_id' => 999];
        $this->postJson('/graphql', ['query' => self::CREATE, 'variables' => ['folder' => (string) $folder->id, 'input' => $input]])
            ->assertOk()->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseCount('tasks', 0);
    }

    #[TestWith(['TODO', 1])]
    #[TestWith(['DOING', 2])]
    #[TestWith(['DONE', 3])]
    public function test_status_updates_preserve_title_and_overdue_date(string $status, int $stored): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'Asia/Tokyo'));
        $task = Task::factory()->create(['title' => 'keep title', 'due_date' => '2026-10-01']);

        $this->actingAs($task->folder->user)->postJson('/graphql', [
            'query' => self::STATUS,
            'variables' => ['folder' => (string) $task->folder_id, 'id' => (string) $task->id, 'status' => $status],
        ])->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.updateTaskStatus.status', $status)
            ->assertJsonPath('data.updateTaskStatus.due_date', '2026-10-01');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'keep title', 'status' => $stored]);
        $this->assertSame('2026-10-01', $task->fresh()->due_date->toDateString());
    }

    public function test_full_update_validates_and_allows_an_overdue_date(): void
    {
        $this->freezeTime();
        $task = Task::factory()->create();
        $variables = ['folder' => (string) $task->folder_id, 'id' => (string) $task->id, 'input' => ['title' => 'edited', 'status' => 'DOING', 'due_date' => '2020-01-01']];
        $this->actingAs($task->folder->user);

        $this->postJson('/graphql', ['query' => self::UPDATE, 'variables' => $variables])
            ->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.updateTask.title', 'edited');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'edited', 'status' => 2]);
        $this->assertSame('2020-01-01', $task->fresh()->due_date->toDateString());

        $variables['input']['title'] = '';
        $this->postJson('/graphql', ['query' => self::UPDATE, 'variables' => $variables])
            ->assertOk()->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'edited']);
    }

    public function test_rejects_other_users_folders_and_tasks_without_disclosing_or_writing(): void
    {
        $this->freezeTime();
        $task = Task::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other);
        $variables = ['folder' => (string) $task->folder_id, 'id' => (string) $task->id];
        $queries = [
            ['query($folder: ID!) { tasks(folder_id: $folder) { data { id title } } }', ['folder' => $variables['folder']]],
            ['query($folder: ID!, $id: ID!) { task(folder_id: $folder, id: $id) { id title } }', $variables],
            [self::CREATE, ['folder' => $variables['folder'], 'input' => ['title' => 'forbidden', 'due_date' => today()->toDateString()]]],
            [self::STATUS, $variables + ['status' => 'DONE']],
            [self::UPDATE, $variables + ['input' => ['title' => 'forbidden', 'due_date' => today()->toDateString(), 'status' => 'DONE']]],
        ];
        foreach ($queries as [$query, $input]) {
            $this->postJson('/graphql', ['query' => $query, 'variables' => $input])
                ->assertOk()->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND')->assertDontSee($task->title);
        }
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title, 'status' => 1]);
    }

    public function test_rejects_a_task_from_another_owned_folder_and_invalid_status(): void
    {
        $task = Task::factory()->create();
        $second = Folder::factory()->for($task->folder->user)->create();
        $this->actingAs($task->folder->user);
        $variables = ['folder' => (string) $second->id, 'id' => (string) $task->id, 'status' => 'DONE'];

        $this->postJson('/graphql', ['query' => self::STATUS, 'variables' => $variables])
            ->assertOk()->assertJsonPath('errors.0.extensions.code', 'NOT_FOUND');
        $variables = ['folder' => (string) $task->folder_id, 'id' => (string) $task->id, 'status' => 'INVALID'];
        $this->postJson('/graphql', ['query' => self::STATUS, 'variables' => $variables])
            ->assertOk()->assertJsonStructure(['errors' => [['message']]]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 1]);
    }

    public function test_rejects_cross_site_graphql_mutations_without_csrf(): void
    {
        $this->freezeTime();
        $folder = Folder::factory()->create();
        $this->app->instance('env', 'local');

        $this->actingAs($folder->user)->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/graphql', [
            'query' => self::CREATE,
            'variables' => ['folder' => (string) $folder->id, 'input' => ['title' => 'forged', 'due_date' => today()->toDateString()]],
        ])->assertStatus(419);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_graphql_get_requests_cannot_execute_mutations(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/graphql?query=mutation%20%7B%20__typename%20%7D')
            ->assertMethodNotAllowed();
    }
}
