<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskExtraction extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['read_count' => 0, 'candidate_count' => 0, 'duplicate_count' => 0];
}
