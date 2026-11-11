<?php

namespace Database\Factories;

use App\Models\TaskExtraction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskCandidateFactory extends Factory
{
    public function definition(): array
    {
        return ['task_extraction_id' => TaskExtraction::factory(),
            'user_id' => fn (array $attributes) => TaskExtraction::findOrFail($attributes['task_extraction_id'])->user_id,
            'message_id' => fake()->unique()->regexify('[a-f0-9]{16}'), 'subject' => 'Project review', 'sender' => 'review@example.test',
            'received_at' => '2026-11-11T12:00:00+09:00', 'title' => 'レビューの指摘を確認する',
            'description' => '指摘内容と現在の対応状況を確認する。', 'reason' => '対応が必要な可能性がある。',
            'evidence' => 'Please review the change.', 'due_date' => null, 'status' => 'pending'];
    }
}
