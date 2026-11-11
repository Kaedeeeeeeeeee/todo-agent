<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GmailConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'account' => 'owner@example.test', 'status' => 'connected', 'verified_at' => now()];
    }
}
