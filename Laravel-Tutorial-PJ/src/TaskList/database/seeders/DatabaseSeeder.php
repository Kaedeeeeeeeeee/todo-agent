<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        Artisan::call('tasklist:prepare-local');
        $this->call([FoldersTableSeeder::class, TasksTableSeeder::class]);
    }
}
