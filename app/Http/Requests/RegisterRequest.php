<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RegisterRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed'],
            'shop_name' => ['required', 'string', 'max:150'], 'address' => ['required', 'string', 'max:255'],
            'area_id' => ['required', Rule::exists('areas', 'id')->where('is_active', true)]];
    }
}
