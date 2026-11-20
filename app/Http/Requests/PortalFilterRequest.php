<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class PortalFilterRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:30'],
            'role' => ['nullable', 'string', 'max:40'], 'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'], 'shipper_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', Rule::when(fn ($input) => is_string($input->from) && $input->from !== '', 'after_or_equal:from')]];
    }
}
