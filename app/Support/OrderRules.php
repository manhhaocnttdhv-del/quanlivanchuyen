<?php

namespace App\Support;

use Illuminate\Validation\Rule;

class OrderRules
{
    public static function details(bool $complete): array
    {
        $required = $complete ? 'required' : 'nullable';

        return [
            'pickup_address_id' => [$required, 'integer', 'min:1'],
            'area_id' => [$required, 'integer', 'min:1'],
            'recipient_name' => [$required, 'string', 'max:150'],
            'recipient_phone' => [$required, 'string', 'regex:/^0[0-9]{9}$/'],
            'recipient_address' => [$required, 'string', 'min:5', 'max:500'],
            'weight_grams' => [$required, 'integer', 'between:1,20000'],
            'parcel_count' => ['required', 'integer', 'between:1,20'],
            'service' => ['required', Rule::in(['standard', 'express'])],
            'fee_payer' => ['required', Rule::in(['sender', 'recipient'])],
            'cod_amount' => ['required', 'integer', 'between:0,999999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'max:20', ...($complete ? ['min:1'] : [])],
            'items.*' => ['array:name,quantity'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'between:1,9999'],
        ];
    }

    public static function attributes(): array
    {
        return ['pickup_address_id' => 'Địa chỉ lấy hàng', 'area_id' => 'Khu vực nhận', 'recipient_name' => 'Người nhận', 'recipient_phone' => 'Điện thoại nhận', 'recipient_address' => 'Địa chỉ nhận', 'weight_grams' => 'Khối lượng (g)', 'parcel_count' => 'Số kiện', 'service' => 'Dịch vụ', 'fee_payer' => 'Người trả phí', 'cod_amount' => 'COD', 'items' => 'Hàng hóa', 'items.*.name' => 'Tên hàng', 'items.*.quantity' => 'Số lượng', 'revision' => 'Phiên bản đơn'];
    }

    public static function messages(): array
    {
        return ['required' => ':attribute là bắt buộc.', 'integer' => ':attribute phải là số nguyên.',
            'between.numeric' => ':attribute phải từ :min đến :max.', 'string' => ':attribute phải là văn bản.',
            'array' => ':attribute không đúng cấu trúc.', 'min.array' => ':attribute cần ít nhất :min dòng.',
            'max.array' => ':attribute tối đa :max dòng.', 'max.string' => ':attribute tối đa :max ký tự.',
            'min.string' => ':attribute cần ít nhất :min ký tự.', 'in' => ':attribute không hợp lệ.',
            'regex' => ':attribute không đúng định dạng.'];
    }
}
