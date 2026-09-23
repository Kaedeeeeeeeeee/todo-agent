<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateFolder extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'フォルダ名を入力してください。',
            'title.string' => 'フォルダ名は文字列で入力してください。',
            'title.max' => 'フォルダ名は20文字以内で入力してください。',
        ];
    }
}
