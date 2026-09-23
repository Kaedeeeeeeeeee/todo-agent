<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_policy_only_grants_access_to_its_owner(): void
    {
        $folder = Folder::factory()->create();
        $other = User::factory()->create();
        $this->assertTrue(Gate::forUser($folder->user)->allows('view', $folder));
        $this->assertFalse(Gate::forUser($other)->allows('view', $folder));
    }

    public function test_every_folder_and_task_route_rejects_guests_and_other_users(): void
    {
        $task = Task::factory()->create();
        $folder = $task->folder;
        $other = User::factory()->create();
        $routes = [
            ['GET', "/folders/{$folder->id}/tasks"],
            ['GET', "/folders/{$folder->id}/edit"], ['POST', "/folders/{$folder->id}/edit"],
            ['GET', "/folders/{$folder->id}/delete"], ['POST', "/folders/{$folder->id}/delete"],
            ['GET', "/folders/{$folder->id}/tasks/create"], ['POST', "/folders/{$folder->id}/tasks/create"],
            ['GET', "/folders/{$folder->id}/tasks/{$task->id}/edit"], ['POST', "/folders/{$folder->id}/tasks/{$task->id}/edit"],
            ['GET', "/folders/{$folder->id}/tasks/{$task->id}/delete"], ['POST', "/folders/{$folder->id}/tasks/{$task->id}/delete"],
        ];
        foreach ($routes as [$method, $url]) {
            $this->call($method, $url)->assertRedirect(route('login'));
        }
        $this->get('/folders/create')->assertRedirect(route('login'));
        $this->post('/folders/create', ['title' => 'guest'])->assertRedirect(route('login'));
        $this->actingAs($other);
        foreach ($routes as [$method, $url]) {
            $this->call($method, $url, ['title' => 'forbidden', 'status' => 3, 'due_date' => '2026-12-31'])
                ->assertForbidden()->assertSee('このページを操作する権限がありません')->assertDontSee($task->title);
        }
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title, 'status' => 1]);
        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'title' => $folder->title]);
        $this->assertDatabaseCount('tasks', 1);
    }

    #[TestWith(['GET', 'edit'])]
    #[TestWith(['POST', 'edit'])]
    #[TestWith(['GET', 'delete'])]
    #[TestWith(['POST', 'delete'])]
    public function test_task_from_another_folder_returns_404(string $method, string $action): void
    {
        $task = Task::factory()->create();
        $otherFolder = Folder::factory()->for($task->folder->user)->create();
        $this->actingAs($task->folder->user)
            ->call($method, "/folders/{$otherFolder->id}/tasks/{$task->id}/{$action}", ['title' => 'wrong', 'status' => 3, 'due_date' => '2026-12-31'])
            ->assertNotFound()->assertSee('ページが見つかりません');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'folder_id' => $task->folder_id, 'title' => $task->title]);
    }

    public function test_missing_folder_task_and_route_use_custom_404(): void
    {
        $folder = Folder::factory()->create();
        $this->actingAs($folder->user);
        foreach (['/folders/999999/tasks', "/folders/{$folder->id}/tasks/999999/edit", '/not-a-page'] as $url) {
            $this->get($url)->assertNotFound()->assertSee('ページが見つかりません');
        }
    }

    public function test_only_own_folders_are_listed_and_user_content_is_escaped(): void
    {
        $task = Task::factory()->create(['title' => '<script>alert(1)</script>']);
        $task->folder->title = '<b>Folder</b>';
        $task->folder->save();
        $task->folder->user->update(['name' => '<script>bad()</script>']);
        Folder::factory()->create(['title' => '他人の秘密']);
        $this->actingAs($task->folder->user)->get(route('tasks.index', $task->folder))
            ->assertOk()->assertDontSee('他人の秘密')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;b&gt;Folder&lt;/b&gt;', false)
            ->assertDontSee('<script>bad()</script>', false);
    }

    public function test_cross_site_post_without_csrf_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->app->instance('env', 'local');
        $this->actingAs($user)->withHeader('Sec-Fetch-Site', 'cross-site')
            ->post('/folders/create', ['title' => 'forged'])
            ->assertStatus(419)->assertSee('ページの有効期限が切れました');
        $this->assertDatabaseCount('folders', 0);
    }
}
