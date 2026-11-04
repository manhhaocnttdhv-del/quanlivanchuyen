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
            'admin' => ['Quản trị viên', array_keys($permissions)],
            'dispatcher' => ['Điều phối', ['orders.review', 'logistics.dispatch', 'logistics.approve']],
            'warehouse' => ['Nhân viên kho', ['logistics.warehouse']], 'shipper' => ['Nhân viên giao hàng', []],
            'accountant' => ['Kế toán', ['banks.verify']], 'support' => ['Chăm sóc khách hàng', ['shops.review']],
            'shop' => ['Chủ cửa hàng', []],
        ];
        foreach ($roles as $name => [$label,$grants]) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $label]);
            $role->permissions()->sync(Permission::whereIn('name', $grants)->pluck('id'));
        }
        foreach (config('shipping.provinces') as $code => $province) {
            Area::firstOrCreate(['code' => $code], ['name' => $province['name'], 'region' => $province['region'], 'is_active' => true]);
        }
    }
}
