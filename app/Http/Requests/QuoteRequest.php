<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin' => ['required', 'string', Rule::exists('areas', 'code')->where('is_active', true)],
            'destination' => ['required', 'string', Rule::exists('areas', 'code')->where('is_active', true)],
            'weight' => ['required', 'integer', 'between:1,20000'],
            'service' => ['required', Rule::in(array_keys(config('shipping.services')))],
        ];
    }

    public function attributes(): array
    {
        return [
            'origin' => 'Tỉnh/thành gửi', 'destination' => 'Tỉnh/thành nhận',
            'weight' => 'Khối lượng', 'service' => 'Dịch vụ',
            'sender_name' => 'Tên người gửi', 'sender_phone' => 'Số điện thoại người gửi',
            'sender_address' => 'Địa chỉ người gửi', 'recipient_name' => 'Tên người nhận',
            'recipient_phone' => 'Số điện thoại người nhận', 'recipient_address' => 'Địa chỉ người nhận',
            'goods' => 'Tên hàng hóa', 'cod' => 'Tiền thu hộ', 'fee_payer' => 'Người trả phí', 'note' => 'Ghi chú',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Vui lòng nhập hoặc chọn :attribute.',
            'in' => ':attribute không hợp lệ. Vui lòng chọn lại.',
            'integer' => ':attribute phải là số nguyên.',
            'between.numeric' => ':attribute phải từ :min đến :max.',
            'min.string' => ':attribute cần ít nhất :min ký tự.',
            'max.string' => ':attribute tối đa :max ký tự.',
            'string' => ':attribute không hợp lệ.',
            'regex' => ':attribute phải gồm 10 chữ số, bắt đầu bằng 0.',
        ];
    }
}
