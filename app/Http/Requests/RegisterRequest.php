<?php

namespace App\Http\Requests;

use App\Models\Shop;
use Illuminate\Validation\Rule;

class RegisterRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['account_type' => ['required', Rule::in(array_keys(Shop::ACCOUNT_TYPES))],
            'name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed'],
            'shop_name' => ['exclude_unless:account_type,business', 'required_if:account_type,business', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:255'],
            'area_id' => ['required', Rule::exists('areas', 'id')->where('is_active', true)]];
    }
}
