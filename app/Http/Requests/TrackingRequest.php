<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->tracking_code)) {
            $this->merge(['tracking_code' => strtoupper(trim($this->tracking_code))]);
        }
    }

    public function rules(): array
    {
        return [
            'tracking_code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/'],
            'phone_suffix' => ['required', 'regex:/^[0-9]{4}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'tracking_code.required' => 'Vui lòng nhập mã đơn hàng.',
            'tracking_code.*' => 'Mã đơn chỉ gồm chữ, số và dấu gạch ngang, tối đa 40 ký tự.',
            'phone_suffix.*' => 'Vui lòng nhập đúng 4 số cuối điện thoại người nhận.',
        ];
    }
}
