<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return ['name' => 'Tên', 'email' => 'Email', 'phone' => 'Số điện thoại', 'password' => 'Mật khẩu', 'address' => 'Địa chỉ',
            'area_id' => 'Khu vực', 'reason' => 'Lý do', 'contact_name' => 'Người liên hệ', 'shop_name' => 'Tên cửa hàng',
            'bank_name' => 'Ngân hàng', 'holder_name' => 'Chủ tài khoản', 'account_number' => 'Số tài khoản', 'status' => 'Trạng thái',
            'identity' => 'Email hoặc điện thoại', 'current_password' => 'Mật khẩu hiện tại'];
    }

    public function messages(): array
    {
        return ['required' => ':attribute là bắt buộc.', 'email' => 'Email không đúng định dạng.', 'unique' => ':attribute đã được sử dụng.',
            'exists' => ':attribute không hợp lệ.', 'in' => ':attribute không hợp lệ.', 'confirmed' => 'Xác nhận mật khẩu không khớp.',
            'min.string' => ':attribute cần ít nhất :min ký tự.', 'max.string' => ':attribute tối đa :max ký tự.',
            'regex' => ':attribute không đúng định dạng.', 'integer' => ':attribute phải là số nguyên.',
            'boolean' => ':attribute phải là giá trị có/không.', 'string' => ':attribute phải là văn bản.'];
    }
}
