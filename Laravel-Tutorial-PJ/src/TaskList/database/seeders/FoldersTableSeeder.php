<?php

namespace Database\Seeders;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Seeder;

class FoldersTableSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'learner@tasklist.test')->firstOrFail();
        foreach (['Laravelの勉強', '入社準備', '日常のタスク'] as $title) {
            if (! $user->folders()->where('title', $title)->exists()) {
                $folder = new Folder;
                $folder->title = $title;
                $user->folders()->save($folder);
            }
        }
    }
}
