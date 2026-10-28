<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ShopProfileRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'address' => ['required', 'string', 'max:255'],
            'area_id' => ['required', Rule::exists('areas', 'id')->where('is_active', true)]];
    }
}
