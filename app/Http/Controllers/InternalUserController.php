<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternalUserRequest;
use App\Http\Requests\PortalFilterRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\IdentityService;

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

        return view('portal.users.index', ['users' => $users, 'roles' => Role::pluck('label', 'name')]);
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
        return view('portal.users.form', ['record' => $user, 'roles' => Role::where('name', '!=', 'shop')->get(), 'warehouses' => Warehouse::where('is_active', true)->get()]);
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
