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
        $warehouse = $this->route('warehouse');

        return ['code' => ['required', 'regex:/^[A-Z0-9-]{2,30}$/', Rule::unique('warehouses')->ignore($warehouse?->id)],
            'name' => ['required', 'string', 'max:100'], 'address' => ['required', 'string', 'max:255'],
            'area_id' => ['bail', 'required', 'integer', Rule::exists('areas', 'id')->where(fn ($q) => $q->where('is_active', true)->when($warehouse, fn ($q) => $q->orWhere('id', $warehouse->area_id)))],
            'is_active' => ['required', 'boolean']];
    }
}
