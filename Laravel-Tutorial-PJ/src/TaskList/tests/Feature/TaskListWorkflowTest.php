<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class TaskListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_empty_home_and_can_create_their_own_folder(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk()->assertSee('最初のフォルダを作りましょう');
        $this->get('/folders/create')->assertOk();
        $this->post('/folders/create', ['title' => '学習メモ', 'user_id' => $other->id])->assertRedirect();
        $this->assertDatabaseHas('folders', ['title' => '学習メモ', 'user_id' => $user->id]);
        $folder = $user->folders()->firstOrFail();
        $this->get('/')->assertRedirect(route('tasks.index', $folder));
    }

    public function test_folder_rename_preserves_tasks_and_rejects_blank_title(): void
    {
        $folder = Folder::factory()->create(['title' => '入社準備']);
        $task = Task::factory()->for($folder)->create();
        $url = "/folders/{$folder->id}/edit";
        $this->actingAs($folder->user)->get($url)->assertOk()->assertSee('入社準備');
        $this->post($url, ['title' => '入社準備メモ'])->assertRedirect(route('tasks.index', $folder));
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'title' => '入社準備メモ']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'folder_id' => $folder->id]);
        $this->from($url)->post($url, ['title' => '   '])
            ->assertRedirect($url)->assertSessionHasErrors(['title' => 'フォルダ名を入力してください。']);
        $this->assertSame('入社準備メモ', $folder->fresh()->title);
    }

    public function test_folder_title_length_is_checked_on_server(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/folders/create', ['title' => str_repeat('あ', 21)])
            ->assertSessionHasErrors(['title' => 'フォルダ名は20文字以内で入力してください。']);
        $this->assertDatabaseCount('folders', 0);
    }

    public function test_task_creation_uses_selected_folder_and_initial_status(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(12, 0));
        $folder = Folder::factory()->create();
        $other = Folder::factory()->create();
        $url = "/folders/{$folder->id}/tasks/create";
        $this->actingAs($folder->user)->get($url)->assertOk();
        $this->post($url, ['title' => '教材を読む', 'due_date' => '2026-09-24', 'status' => 3, 'folder_id' => $other->id])->assertRedirect(route('tasks.index', $folder));
        $this->assertDatabaseHas('tasks', ['folder_id' => $folder->id, 'title' => '教材を読む', 'status' => 1]);
        $this->assertSame('2026-09-24', $folder->tasks()->firstOrFail()->due_date->toDateString());
        $this->from($url)->post($url, ['title' => '過去の日付', 'due_date' => '2026-09-22'])
            ->assertSessionHasErrors(['due_date' => '期限は今日以降の日付にしてください。']);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_overdue_task_can_be_completed_without_changing_its_due_date(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(12, 0));
        $task = Task::factory()->create(['due_date' => '2026-09-15']);
        $url = "/folders/{$task->folder_id}/tasks/{$task->id}/edit";
        $this->actingAs($task->folder->user)->get($url)->assertOk()->assertSee('2026-09-15');
        $this->post($url, ['title' => '書類確認済み', 'status' => 3, 'due_date' => '2026-09-15', 'folder_id' => 999])
            ->assertRedirect(route('tasks.index', $task->folder));
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'folder_id' => $task->folder_id, 'status' => 3, 'title' => '書類確認済み']);
        $this->assertSame('2026-09-15', $task->fresh()->due_date->toDateString());
        $this->get(route('tasks.index', $task->folder))->assertSee('完了')->assertSee('書類確認済み');
    }

    #[TestWith(['status', 999])]
    #[TestWith(['status', 'invalid'])]
    #[TestWith(['status', null])]
    #[TestWith(['title', ''])]
    #[TestWith(['due_date', '2026-02-30'])]
    #[TestWith(['due_date', 'not-a-date'])]
    public function test_invalid_edit_does_not_change_task(string $field, mixed $value): void
    {
        $task = Task::factory()->create(['title' => '元のタスク', 'status' => 1]);
        $url = "/folders/{$task->folder_id}/tasks/{$task->id}/edit";
        $payload = ['title' => '変更後', 'status' => 2, 'due_date' => '2026-12-31'];
        $payload[$field] = $value;
        $this->actingAs($task->folder->user)->from($url)->post($url, $payload)->assertRedirect($url)->assertSessionHasErrors($field);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => '元のタスク', 'status' => 1]);
    }

    public function test_task_deletion_confirmation_is_read_only_and_post_deletes_only_selected_task(): void
    {
        $task = Task::factory()->create();
        $sibling = Task::factory()->for($task->folder)->create();
        $url = "/folders/{$task->folder_id}/tasks/{$task->id}/delete";
        $this->actingAs($task->folder->user)->get($url)->assertOk()->assertSee($task->title)->assertSee('キャンセル');
        $this->assertModelExists($task);
        $this->post($url)->assertRedirect(route('tasks.index', $task->folder));
        $this->assertModelMissing($task);
        $this->assertModelExists($sibling);
        $this->assertModelExists($task->folder);
    }

    public function test_deleting_last_folder_deletes_its_tasks_and_returns_to_empty_home(): void
    {
        $folder = Folder::factory()->create();
        Task::factory()->for($folder)->count(2)->create();
        $unrelated = Task::factory()->create();
        $url = "/folders/{$folder->id}/delete";
        $this->actingAs($folder->user)->get($url)->assertOk()->assertSee('2 件');
        $this->assertModelExists($folder);
        $this->post($url)->assertRedirect(route('home'));
        $this->assertModelMissing($folder);
        $this->assertDatabaseMissing('tasks', ['folder_id' => $folder->id]);
        $this->assertModelExists($unrelated);
        $this->get('/')->assertOk()->assertSee('最初のフォルダを作りましょう');
    }

    public function test_folder_delete_failure_rolls_back_children_and_reports_error(): void
    {
        config(['app.debug' => false]);
        $task = Task::factory()->create();
        $this->actingAs($task->folder->user);
        Exceptions::fake();
        Folder::deleting(function (): void {
            throw new RuntimeException('Simulated database failure');
        });
        try {
            $this->post("/folders/{$task->folder_id}/delete")->assertStatus(500)->assertSee('サーバーでエラーが発生しました')->assertDontSee('Simulated database failure');
            $this->assertModelExists($task);
            $this->assertModelExists($task->folder);
            Exceptions::assertReported(RuntimeException::class);
        } finally {
            Folder::flushEventListeners();
        }
    }
}
