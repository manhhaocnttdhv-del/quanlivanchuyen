<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ProfileRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($this->user()->id)],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', Rule::unique('users')->ignore($this->user()->id)]];
    }
}
