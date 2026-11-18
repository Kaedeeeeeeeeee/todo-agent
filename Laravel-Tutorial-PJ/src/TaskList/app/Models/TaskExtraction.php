<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskExtraction extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    public function getInstructionsAttribute(): string
    {
        return $this->scan_options['instructions'] ?? '';
    }

    protected function casts(): array
    {
        return ['retry_at' => 'datetime', 'completed_at' => 'datetime', 'scan_options' => 'array', 'has_more' => 'boolean', 'mail_plan' => 'array', 'listing_tokens' => 'array', 'batch_started_at' => 'datetime', 'next_batch_at' => 'datetime'];
    }

    protected $attributes = ['read_count' => 0, 'candidate_count' => 0, 'duplicate_count' => 0, 'stage' => 'legacy', 'scanned_count' => 0, 'batch_count' => 0, 'total_count' => null];
}
