<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    public const STATUS = [
        1 => ['label' => '未着手', 'class' => 'status-todo'],
        2 => ['label' => '着手中', 'class' => 'status-doing'],
        3 => ['label' => '完了', 'class' => 'status-done'],
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'status' => 'integer', 'folder_id' => 'integer'];
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::make(get: fn () => self::STATUS[$this->status]['label'] ?? '不明');
    }

    protected function statusClass(): Attribute
    {
        return Attribute::make(get: fn () => self::STATUS[$this->status]['class'] ?? 'status-unknown');
    }

    protected function formattedDueDate(): Attribute
    {
        return Attribute::make(get: fn () => $this->due_date->format('Y/m/d'));
    }
}
