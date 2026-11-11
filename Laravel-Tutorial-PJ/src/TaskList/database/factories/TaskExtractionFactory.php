<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskExtractionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'scope' => 'GITHUB', 'status' => 'succeeded'];
    }
}
