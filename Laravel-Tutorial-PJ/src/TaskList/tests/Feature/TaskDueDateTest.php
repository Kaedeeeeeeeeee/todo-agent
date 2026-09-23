<?php

namespace Tests\Feature;

use App\Http\Requests\CreateTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TaskDueDateTest extends TestCase
{
    public function test_due_date_validation(): void
    {
        $this->travelTo(Carbon::parse('2026-09-09 12:00:00'));

        $cases = [
            ['2026-09-08', false],
            ['2026-09-09', true],
            ['2026-09-10', true],
            ['not-a-date', false],
        ];

        $rules = (new CreateTask)->rules();

        foreach ($cases as [$dueDate, $expected]) {
            $validator = Validator::make([
                'title' => 'テスト用タスク',
                'due_date' => $dueDate,
            ], $rules);

            $this->assertSame(
                $expected,
                $validator->passes(),
                "期限 {$dueDate} の判定が想定と違います。"
            );
        }
    }
}
