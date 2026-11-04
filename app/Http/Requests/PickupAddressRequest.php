<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PickupAddressRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['contact_name' => ['required', 'string', 'max:100'], 'phone' => ['required', 'regex:/^0[0-9]{9}$/'],
            'address' => ['required', 'string', 'max:255'], 'area_id' => ['required', Rule::exists('areas', 'id')->where('is_active', true)],
            'is_active' => ['required', 'boolean'], 'is_default' => ['required', 'boolean']];
    }
}
