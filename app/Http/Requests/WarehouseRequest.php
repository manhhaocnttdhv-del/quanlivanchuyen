<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class WarehouseRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'regex:/^[A-Z0-9-]{2,30}$/', Rule::unique('warehouses')->ignore($this->route('warehouse')?->id)],
            'name' => ['required', 'string', 'max:100'], 'address' => ['required', 'string', 'max:255'],
            'area_id' => ['required', Rule::exists('areas', 'id')->where('is_active', true)], 'is_active' => ['required', 'boolean']];
    }
}
