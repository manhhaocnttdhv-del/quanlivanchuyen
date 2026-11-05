<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = ['users.manage' => 'Quản lý tài khoản', 'catalog.manage' => 'Quản lý danh mục', 'shops.review' => 'Duyệt shop', 'banks.verify' => 'Xác minh ngân hàng', 'orders.review' => 'Duyệt vận đơn', 'audit.view' => 'Đọc nhật ký', 'logistics.dispatch' => 'Điều phối nhiệm vụ', 'logistics.warehouse' => 'Nhập xuất và kiểm kê', 'logistics.approve' => 'Duyệt sai lệch và kiểm kê'];
        foreach ($permissions as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }
        $roles = [
            'admin' => array_keys($permissions),
            'operations' => ['shops.review', 'orders.review', 'logistics.dispatch', 'logistics.warehouse', 'logistics.approve'],
            'shipper' => [],
            'shop' => [],
        ];
        foreach ($roles as $name => $grants) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => Role::LABELS[$name]]);
            $role->permissions()->sync(Permission::whereIn('name', $grants)->pluck('id'));
        }
        foreach (config('shipping.provinces') as $code => $province) {
            Area::firstOrCreate(['code' => $code], ['name' => $province['name'], 'region' => $province['region'], 'is_active' => true]);
        }
    }
}
