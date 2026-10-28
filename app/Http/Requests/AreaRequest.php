<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AreaRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique('areas')->ignore($this->route('area')?->id)],
            'name' => ['required', 'string', 'max:100'], 'region' => ['required', Rule::in(['north', 'central', 'south'])], 'is_active' => ['required', 'boolean']];
    }
}
