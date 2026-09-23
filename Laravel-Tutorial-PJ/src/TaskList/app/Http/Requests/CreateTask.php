<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTask extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'タスク名を入力してください。',
            'title.string' => 'タスク名は文字列で入力してください。',
            'title.max' => 'タスク名は100文字以内で入力してください。',
            'due_date.required' => '期限を入力してください。',
            'due_date.date_format' => '期限は正しい日付で入力してください。',
            'due_date.after_or_equal' => '期限は今日以降の日付にしてください。',
        ];
    }
}
