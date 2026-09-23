<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Validation\Rule;

class EditTask extends CreateTask
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['due_date'] = ['required', 'date_format:Y-m-d'];
        $rules['status'] = ['required', 'integer', Rule::in(array_keys(Task::STATUS))];

        return $rules;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.required' => '状態を選択してください。',
            'status.integer' => '状態の値が不正です。',
            'status.in' => '状態は未着手・着手中・完了から選択してください。',
        ]);
    }
}
