<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternalUserRequest;
use App\Http\Requests\PortalFilterRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\IdentityService;

/** Quản trị nhân sự sau middleware users.manage; IdentityService bảo vệ admin cuối và thu hồi phiên cũ. */
class InternalUserController extends Controller
{
    public function index(PortalFilterRequest $request)
    {
        $data = $request->validated();
        $users = User::with(['roles', 'warehouses'])
            ->when($data['q'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%")->orWhere('phone', 'like', "%$search%")))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($q) => $q->where('name', $role)))
            ->latest('id')->paginate(15)->withQueryString();

        return view('portal.users.index', ['users' => $users, 'roles' => Role::whereIn('name', array_keys(Role::LABELS))->pluck('label', 'name')]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(User $user)
    {
        return $this->form($user);
    }

    private function form(?User $user = null)
    {
        // Tài khoản shop đăng ký bằng luồng riêng, không gán vai trò shop từ form nhân sự.
        $retained = $user ? $user->warehouses()->pluck('warehouses.id')->merge($user->requiredShipperWarehouseIds()) : collect();

        return view('portal.users.form', ['record' => $user, 'roles' => Role::whereIn('name', Role::INTERNAL_NAMES)->get(),
            'warehouses' => Warehouse::where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $retained))->get()]);
    }

    public function store(InternalUserRequest $request, IdentityService $service)
    {
        $service->save($request->user(), $request->validated());

        return redirect()->route('users.index')->with('status', 'Đã tạo tài khoản nội bộ.');
    }

    public function update(InternalUserRequest $request, User $user, IdentityService $service)
    {
        $service->save($request->user(), $request->validated(), $user);

        return redirect()->route('users.index')->with('status', 'Đã cập nhật tài khoản, vai trò và phạm vi kho.');
    }
}
