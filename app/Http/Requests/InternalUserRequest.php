<?php

namespace App\Http\Requests;

use App\Models\Role;
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
        $isCustomer = $user?->hasRole('shop') ?? false;
        $roles = Role::whereIn('id', array_filter((array) $this->input('role_ids', []), 'is_scalar'))->pluck('name');
        $needsWarehouse = ! $isCustomer && $this->input('status') === 'active' && ! $roles->contains('admin') && $roles->intersect(['operations', 'shipper'])->isNotEmpty();
        $retained = $user ? $user->warehouses()->pluck('warehouses.id')->merge($user->requiredShipperWarehouseIds())->unique()->all() : [];

        return ['name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['required', 'regex:/^0[0-9]{9}$/', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:10', 'max:100', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_ids' => [$isCustomer ? 'sometimes' : 'required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->whereIn($isCustomer ? 'id' : 'name', $isCustomer ? $user->roles->modelKeys() : Role::INTERNAL_NAMES)],
            'warehouse_ids' => $isCustomer ? ['prohibited'] : [$needsWarehouse ? 'required' : 'nullable', 'array', 'min:'.($needsWarehouse ? '1' : '0')],
            'warehouse_ids.*' => ['integer', 'distinct', Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $retained))],
            'reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['warehouse_ids' => 'Phạm vi kho', 'warehouse_ids.*' => 'Kho phụ trách', 'role_ids' => 'Vai trò'];
    }
}
