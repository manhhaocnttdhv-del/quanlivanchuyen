<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdentityService
{
    public function __construct(private AuditService $audit) {}

    public function save(User $actor, array $data, ?User $user = null): User
    {
        return DB::transaction(function () use ($actor, $data, $user) {
            // Khóa một dòng chung để hai quản trị viên không cùng vô hiệu hóa admin cuối.
            DB::table('business_locks')->where('name', 'active-administrators')->lockForUpdate()->first();
            $user = $user ? User::lockForUpdate()->findOrFail($user->id) : new User;
            $before = $user->only(['name', 'email', 'phone', 'status']);
            if ($user->exists) {
                $before += ['role_ids' => $user->roles()->pluck('roles.id')->all(), 'warehouse_ids' => $user->warehouses()->pluck('warehouses.id')->all()];
            }
            $wasAdmin = $user->exists && $user->hasRole('admin') && $user->status === 'active';
            $adminId = Role::where('name', 'admin')->value('id');
            $removesAdmin = $data['status'] !== 'active' || ! in_array($adminId, $data['role_ids']);
            if ($wasAdmin && $removesAdmin && User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->count() <= 1) {
                throw ValidationException::withMessages(['status' => 'Không thể khóa hoặc bỏ quyền của quản trị viên cuối cùng.']);
            }
            if ($user->id === $actor->id && $data['status'] !== 'active') {
                throw ValidationException::withMessages(['status' => 'Không thể tự khóa tài khoản đang dùng.']);
            }
            if ($user->exists) {
                $requiredWarehouses = $user->requiredShipperWarehouseIds();
                if ($requiredWarehouses->isNotEmpty()) {
                    $keepsShipper = in_array(Role::where('name', 'shipper')->value('id'), $data['role_ids']);
                    if ($data['status'] !== 'active' || ! $keepsShipper) {
                        throw ValidationException::withMessages(['status' => 'Shipper phải kết thúc nhiệm vụ và bàn giao hết kiện trước khi khóa hoặc bỏ vai trò.']);
                    }
                    if ($requiredWarehouses->diff($data['warehouse_ids'] ?? [])->isNotEmpty()) {
                        throw ValidationException::withMessages(['warehouse_ids' => 'Phải giữ các kho còn nhiệm vụ hoặc kiện đang do shipper giữ. Thu hồi và nhận lại kiện trước khi đổi phạm vi.']);
                    }
                }
            }
            $user->fill(Arr::only($data, ['name', 'email', 'phone']));
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->status = $data['status'];
            if ($user->exists) {
                $user->auth_version++;
            }
            $user->save();
            $user->roles()->sync($data['role_ids']);
            $user->warehouses()->sync($data['warehouse_ids'] ?? []);
            if ($user->id === $actor->id) {
                session(['auth_version' => $user->auth_version]);
            }
            $after = array_merge($user->only(['name', 'email', 'phone', 'status']), Arr::only($data, ['role_ids', 'warehouse_ids']));
            $this->audit->record($actor, $user, 'user.saved', $before, $after, $data['reason']);

            return $user;
        });
    }

    public function updateProfile(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $before = $user->only(['name', 'email', 'phone']);
            $user->update($data);
            $this->audit->record($user, $user, 'profile.updated', $before, $data);
        });
    }

    public function changePassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $user->password = $password;
            $user->auth_version++;
            $user->save();
            $this->audit->record($user, $user, 'password.changed', [], ['auth_version' => $user->auth_version]);
            session(['auth_version' => $user->auth_version]);
        });
    }
}
