<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FolderGraphQLTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = 'mutation($input: CreateFolderInput!) { createFolder(input: $input) { id title tasks_url } }';

    public function test_folder_creation_requires_login(): void
    {
        $this->postJson('/graphql', ['query' => self::CREATE, 'variables' => ['input' => ['title' => '仕事']]])->assertUnauthorized();
        $this->assertDatabaseCount('folders', 0);
    }

    public function test_creates_in_the_current_account_and_returns_the_saved_folder_without_redirect(): void
    {
        $otherFolder = Folder::factory()->create();
        $owner = User::factory()->create();
        $response = $this->actingAs($owner)->postJson('/graphql', ['query' => self::CREATE, 'variables' => ['input' => ['title' => '  新しいフォルダ  ']]])
            ->assertOk()->assertJsonMissingPath('errors')->assertJsonPath('data.createFolder.title', '新しいフォルダ');
        $id = $response->json('data.createFolder.id');
        $this->assertDatabaseHas('folders', ['id' => $id, 'user_id' => $owner->id, 'title' => '新しいフォルダ']);
        $this->assertDatabaseHas('folders', ['id' => $otherFolder->id, 'user_id' => $otherFolder->user_id]);
        $response->assertJsonPath('data.createFolder.tasks_url', route('tasks.index', ['folder' => $id]));
        $this->postJson('/graphql', ['query' => '{ folders { data { id title tasks_url } } }'])->assertJsonMissingPath('errors')
            ->assertJsonCount(1, 'data.folders.data')->assertJsonPath('data.folders.data.0.id', $id);
        $this->assertDatabaseCount('tasks', 0);
    }

    #[TestWith(['', 'フォルダ名を入力してください。'])]
    #[TestWith(['   ', 'フォルダ名を入力してください。'])]
    #[TestWith(['あいうえおかきくけこさしすせそたちつてとな', 'フォルダ名は20文字以内で入力してください。'])]
    public function test_invalid_names_do_not_create_folders(string $title, string $message): void
    {
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::CREATE, 'variables' => ['input' => ['title' => $title]]])
            ->assertOk()->assertJsonPath('errors.0.extensions.code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.0.extensions.validation.title.0', $message);
        $this->assertDatabaseCount('folders', 0);
    }

    public function test_folder_owner_cannot_be_supplied_in_the_input(): void
    {
        $other = User::factory()->create();
        $this->actingAs(User::factory()->create())->postJson('/graphql', ['query' => self::CREATE,
            'variables' => ['input' => ['title' => 'Other folder', 'user_id' => $other->id]]])->assertJsonStructure(['errors']);
        $this->assertDatabaseCount('folders', 0);
    }

    public function test_first_folder_home_and_workspace_have_same_page_creation_entries(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/')->assertOk()->assertSee('data-folder-starter', false)->assertSee('data-folder-create-dialog', false);
        $folder = Folder::factory()->create(['user_id' => $owner->id]);
        $this->get(route('tasks.index', ['folder' => $folder]))->assertOk()->assertSee('data-create-folder', false)
            ->assertSee('data-folder-create-dialog', false);
    }
}
