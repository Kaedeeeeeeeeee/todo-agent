<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

class TasksTableSeeder extends Seeder
{
    public function run(): void
    {
        $folder = User::where('email', 'learner@tasklist.test')->firstOrFail()->folders()->where('title', '入社準備')->firstOrFail();
        foreach (['必要書類を確認する', '自己紹介を準備する'] as $title) {
            if (! $folder->tasks()->where('title', $title)->exists()) {
                $task = new Task;
                $task->title = $title;
                $task->status = 1;
                $task->due_date = today()->addWeek();
                $folder->tasks()->save($task);
            }
        }
    }
}
