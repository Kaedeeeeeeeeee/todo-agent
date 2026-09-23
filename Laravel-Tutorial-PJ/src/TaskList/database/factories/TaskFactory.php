<?php

namespace Database\Factories;

use App\Models\Folder;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    public function definition(): array
    {
        return ['folder_id' => Folder::factory(), 'title' => fake()->sentence(3), 'status' => 1, 'due_date' => '2026-12-31'];
    }
}
