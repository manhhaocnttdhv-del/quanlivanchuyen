<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('business_locks')->where('name', 'active-administrators')->lockForUpdate()->first();

            $now = now();
            $roles = [
                'admin' => 'Admin (kiêm kế toán)',
                'operations' => 'Nhân viên vận hành',
                'shipper' => 'Shipper',
                'shop' => 'Chủ cửa hàng',
            ];
            foreach ($roles as $name => $label) {
                DB::table('roles')->insertOrIgnore(['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('roles')->where('name', $name)->update(['label' => $label, 'updated_at' => $now]);
            }

            $mapping = ['dispatcher' => 'operations', 'warehouse' => 'operations', 'support' => 'operations', 'accountant' => 'admin'];
            $ids = DB::table('roles')->pluck('id', 'name');
            $legacyIds = $ids->only(array_keys($mapping))->all();
            if ($legacyIds === []) {
                return;
            }

            foreach ($legacyIds as $name => $id) {
                $targetId = $ids[$mapping[$name]];
                foreach (DB::table('permission_role')->where('role_id', $id)->pluck('permission_id') as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $targetId, 'permission_id' => $permissionId]);
                }
            }

            $affectedIds = array_merge(array_values($legacyIds), [$ids['admin'], $ids['operations']]);
            $userIds = DB::table('role_user')->whereIn('role_id', $affectedIds)->distinct()->pluck('user_id');
            $users = DB::table('users')->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
            $requestId = (string) Str::uuid();
            foreach ($users as $user) {
                $before = DB::table('roles')->join('role_user', 'roles.id', '=', 'role_user.role_id')
                    ->where('role_user.user_id', $user->id)->orderBy('roles.name')->pluck('roles.name')->all();
                $after = collect($before)->map(fn ($name) => $mapping[$name] ?? $name)->unique()->sort()->values()->all();

                foreach ($after as $name) {
                    DB::table('role_user')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $ids[$name]]);
                }
                DB::table('role_user')->where('user_id', $user->id)->whereIn('role_id', $legacyIds)->delete();
                // Thay đổi quyền phải buộc đăng nhập lại, không đụng đến phạm vi kho hoặc chứng từ.
                DB::table('users')->where('id', $user->id)->increment('auth_version');
                DB::table('audit_logs')->insert([
                    'actor_id' => null, 'subject_type' => 'App\\Models\\User', 'subject_id' => $user->id,
                    'event' => 'user.roles_consolidated',
                    'old_values' => json_encode(['roles' => $before], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'new_values' => json_encode(['roles' => $after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'reason' => 'Gộp vai trò: vận hành kiêm điều phối/kho/CSKH; admin kiêm kế toán.',
                    'request_id' => $requestId, 'created_at' => $now,
                ]);
            }

            DB::table('permission_role')->whereIn('role_id', $legacyIds)->delete();
            DB::table('roles')->whereIn('id', $legacyIds)->delete();
        });
    }

    public function down(): void
    {
        // Không thể tự suy ra vai trò cũ sau khi nhiều vai trò đã được gộp chung.
        throw new RuntimeException('Không thể tự tách lại vai trò đã gộp. Khôi phục từ bản sao lưu nếu cần quay lại mô hình cũ.');
    }
};
