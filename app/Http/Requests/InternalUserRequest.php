<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class InternalUserRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return ['name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:10', 'max:100', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->whereNot('name', 'shop')],
            'warehouse_ids' => ['nullable', 'array'], 'warehouse_ids.*' => ['integer', 'distinct', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
