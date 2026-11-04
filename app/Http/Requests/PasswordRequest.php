<?php

namespace App\Http\Requests;

class PasswordRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed']];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), ['current_password.current_password' => 'Mật khẩu hiện tại chưa đúng.']);
    }
}
