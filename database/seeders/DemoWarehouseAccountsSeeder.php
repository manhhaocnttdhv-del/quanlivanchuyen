<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\IdentityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoWarehouseAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || strlen((string) env('DEMO_PASSWORD')) < 10) {
            throw new \RuntimeException('Chỉ chạy local/testing với DEMO_PASSWORD tối thiểu 10 ký tự.');
        }

        DB::transaction(function () {
            $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
            $codes = ['KHO-HN', 'KHO-HN-LB', 'KHO-HCM', 'KHO-HCM-TD', 'KHO-DN', 'KHO-DN-LC'];
            foreach ($codes as $index => $code) {
                $warehouse = Warehouse::where('code', $code)->firstOrFail();
                foreach (['operations', 'shipper'] as $offset => $role) {
                    $email = $role.'.'.Str::lower($code).'@guiviet.test';
                    // Chạy lại không đổi mật khẩu, quyền hoặc phạm vi đã được quản trị viên chỉnh.
                    if (User::where('email', $email)->exists()) {
                        continue;
                    }
                    app(IdentityService::class)->save($admin, [
                        'name' => ($role === 'operations' ? 'Vận hành ' : 'Shipper ').$warehouse->name,
                        'email' => $email, 'phone' => '093001'.str_pad((string) ($index * 2 + $offset + 1), 4, '0', STR_PAD_LEFT),
                        'password' => env('DEMO_PASSWORD'), 'status' => 'active',
                        'role_ids' => [Role::where('name', $role)->firstOrFail()->id], 'warehouse_ids' => [$warehouse->id],
                        'reason' => 'Tạo tài khoản demo riêng cho '.$warehouse->code,
                    ]);
                }
            }
        }, 3);
    }
}
