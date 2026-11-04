<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DraftSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100']];
    }

    public function messages(): array
    {
        return ['q.*' => 'Từ khóa tìm kiếm phải là văn bản, tối đa 100 ký tự.'];
    }
}
