<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\FeeVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FeeVersionService;
use App\Services\ShopService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $password = env('DEMO_PASSWORD');
        if (! $password || strlen($password) < 10) {
            throw new \RuntimeException('Đặt DEMO_PASSWORD trong .env trước khi tạo tài khoản demo.');
        }
        $people = ['admin' => 'Quản trị demo', 'dispatcher' => 'Điều phối demo', 'warehouse' => 'Thủ kho demo', 'shipper' => 'Shipper demo', 'accountant' => 'Kế toán demo', 'support' => 'CSKH demo'];
        foreach ($people as $role => $name) {
            $user = User::firstOrCreate(['email' => $role.'@guiviet.test'], ['name' => $name, 'password' => $password, 'phone' => '09000000'.str_pad((string) array_search($role, array_keys($people)), 2, '0', STR_PAD_LEFT)]);
            $user->roles()->syncWithoutDetaching(Role::where('name', $role)->value('id'));
        }
        $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
        foreach (['HN', 'HCM'] as $code) {
            $area = Area::where('code', $code)->firstOrFail();
            $warehouse = Warehouse::firstOrCreate(['code' => 'KHO-'.$code], ['name' => 'Kho '.$area->name, 'address' => 'Địa chỉ demo '.$area->name, 'area_id' => $area->id]);
            foreach (['admin', 'dispatcher', 'warehouse', 'shipper'] as $role) {
                User::where('email', $role.'@guiviet.test')->first()->warehouses()->syncWithoutDetaching($warehouse->id);
            }
        }
        foreach (['shop', 'pending'] as $index => $prefix) {
            if (! User::where('email', $prefix.'@guiviet.test')->exists()) {
                $user = app(ShopService::class)->register(['name' => 'Chủ shop '.$prefix, 'email' => $prefix.'@guiviet.test', 'phone' => '091000000'.($index + 1), 'password' => $password, 'shop_name' => 'Cửa hàng '.$prefix, 'area_id' => Area::where('code', 'HN')->value('id'), 'address' => '10 Đường demo, Hà Nội']);
                app(ShopService::class)->replaceBank($user, $user->shop, ['bank_name' => 'Ngân hàng demo', 'holder_name' => 'CHU SHOP DEMO', 'account_number' => '0000123456789']);
                if ($prefix === 'shop') {
                    app(ShopService::class)->review($admin, $user->shop, ['status' => 'approved', 'reason' => 'Hồ sơ mẫu phục vụ kiểm thử đồ án']);
                    app(ShopService::class)->verifyBank($admin, $user->shop->fresh()->currentBankAccount, 'Thông tin ngân hàng demo đã kiểm tra');
                }
            }
        }
        if (! FeeVersion::where('status', 'published')->exists()) {
            $rules = [];
            foreach (config('shipping.routes') as $route => $rate) {
                foreach (config('shipping.services') as $service => $option) {
                    $rules[] = ['route' => $route, 'service' => $service, 'min_weight_grams' => 1, 'max_weight_grams' => 20000, 'base_weight_grams' => 500, 'step_weight_grams' => 500, 'base_fee' => $rate['base'] + $option['surcharge'], 'step_fee' => $rate['step'], 'max_cod' => 20000000];
                }
            }
            $version = app(FeeVersionService::class)->save($admin, ['name' => 'Biểu phí demo 2026–2027', 'active_from' => '2026-01-01T00:00', 'active_until' => '2028-01-01T00:00', 'rules' => $rules]);
            app(FeeVersionService::class)->publish($admin, $version, 'Biểu phí mẫu phục vụ demo, không phải giá hãng vận chuyển');
        }
    }
}
