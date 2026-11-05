<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\CatalogService;
use App\Services\IdentityService;
use App\Services\OrderService;
use App\Services\ShopService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoShippingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        if (strlen((string) env('DEMO_PASSWORD')) < 10) {
            throw new \RuntimeException('Đặt DEMO_PASSWORD tối thiểu 10 ký tự trước khi chạy seeder demo.');
        }

        DB::transaction(function () {
            $this->call(DemoSeeder::class);
            $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
            $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
            $warehouses = $this->seedWarehouses($admin);
            $pickups = $this->seedPickupAddresses($owner, $warehouses);
            $this->assignWarehouseScopes($admin, $warehouses);
            $this->seedBranchOperators($admin, $warehouses);
            $this->call([DemoOrderSeeder::class, DemoLogisticsSeeder::class]);
            $this->seedSelectionOrders($admin, $owner, $warehouses, $pickups);
        }, 3);
    }

    private function seedWarehouses(User $admin): array
    {
        $samples = [
            ['KHO-HN', 'HN', 'Kho Hà Nội', '10 Đường demo, Hà Nội'],
            ['KHO-HN-LB', 'HN', 'Kho Long Biên', '20 Đường demo Long Biên, Hà Nội'],
            ['KHO-HCM', 'HCM', 'Kho TP. Hồ Chí Minh', '30 Đường demo, TP. Hồ Chí Minh'],
            ['KHO-HCM-TD', 'HCM', 'Kho Thủ Đức', '40 Đường demo Thủ Đức, TP. Hồ Chí Minh'],
            ['KHO-DN', 'DN', 'Kho Đà Nẵng', '50 Đường demo Hải Châu, Đà Nẵng'],
            ['KHO-DN-LC', 'DN', 'Kho Liên Chiểu', '60 Đường demo Liên Chiểu, Đà Nẵng'],
        ];
        $warehouses = [];
        foreach ($samples as [$code, $areaCode, $name, $address]) {
            $warehouse = Warehouse::where('code', $code)->first();
            if (! $warehouse) {
                app(CatalogService::class)->saveWarehouse($admin, [
                    'code' => $code, 'name' => $name, 'address' => $address,
                    'area_id' => Area::where('code', $areaCode)->firstOrFail()->id, 'is_active' => true,
                ]);
                $warehouse = Warehouse::where('code', $code)->firstOrFail();
            }
            $warehouses[$code] = $warehouse;
            foreach (['A-01' => 'Kệ hàng chờ giao', 'B-01' => 'Khu kiểm tra'] as $locationCode => $locationName) {
                $location = WarehouseLocation::firstOrCreate(
                    ['warehouse_id' => $warehouse->id, 'code' => $locationCode],
                    ['name' => $locationName.' '.$locationCode, 'is_active' => true],
                );
                if ($location->wasRecentlyCreated) {
                    app(AuditService::class)->record($admin, $location, 'location.created', [], $location->only(['warehouse_id', 'code', 'name', 'is_active']), 'Tạo vị trí kho demo');
                }
            }
        }

        return $warehouses;
    }

    private function seedPickupAddresses(User $owner, array $warehouses): array
    {
        $pickups = [];
        foreach (collect($warehouses)->pluck('area_id')->unique() as $areaId) {
            $pickup = $owner->shop->addresses()->where('area_id', $areaId)->orderByDesc('is_active')->orderByDesc('is_default')->orderBy('id')->first();
            if (! $pickup) {
                $area = Area::findOrFail($areaId);
                app(ShopService::class)->saveAddress($owner, $owner->shop, [
                    'contact_name' => 'Điểm lấy hàng '.$area->name, 'phone' => $owner->phone,
                    'area_id' => $areaId, 'address' => '10 Đường lấy hàng demo, '.$area->name,
                    'is_active' => true, 'is_default' => false,
                ]);
                $pickup = $owner->shop->addresses()->where('area_id', $areaId)->firstOrFail();
            }
            $pickups[$areaId] = $pickup;
        }

        return $pickups;
    }

    private function assignWarehouseScopes(User $admin, array $warehouses): void
    {
        $warehouseIds = collect($warehouses)->pluck('id')->all();
        foreach (['admin', 'operations', 'shipper'] as $role) {
            $user = User::where('email', $role.'@guiviet.test')->firstOrFail();
            $before = $user->warehouses()->pluck('warehouses.id')->all();
            $changes = $user->warehouses()->syncWithoutDetaching($warehouseIds);
            if ($changes['attached'] !== []) {
                app(AuditService::class)->record($admin, $user, 'user.demo_warehouses_assigned', ['warehouse_ids' => $before],
                    ['warehouse_ids' => $user->warehouses()->pluck('warehouses.id')->all()], 'Gán phạm vi kho cho tài khoản demo');
            }
        }
    }

    private function seedBranchOperators(User $admin, array $warehouses): void
    {
        $role = Role::where('name', 'operations')->firstOrFail();
        $branches = [
            ['hn', 'Hà Nội', ['KHO-HN', 'KHO-HN-LB']],
            ['hcm', 'TP.HCM', ['KHO-HCM', 'KHO-HCM-TD']],
            ['dn', 'Đà Nẵng', ['KHO-DN', 'KHO-DN-LC']],
        ];
        foreach ($branches as $index => [$code, $name, $warehouseCodes]) {
            $email = 'operations.'.$code.'@guiviet.test';
            // Tài khoản đã có giữ nguyên mật khẩu, trạng thái và phạm vi do admin chỉnh.
            if (User::where('email', $email)->exists()) {
                continue;
            }
            app(IdentityService::class)->save($admin, [
                'name' => 'Vận hành chi nhánh '.$name, 'email' => $email, 'phone' => '092000020'.($index + 1),
                'password' => env('DEMO_PASSWORD'), 'status' => 'active',
                'role_ids' => [$role->id],
                'warehouse_ids' => array_map(fn ($warehouseCode) => $warehouses[$warehouseCode]->id, $warehouseCodes),
                'reason' => 'Tạo tài khoản vận hành chi nhánh demo '.$name,
            ]);
        }
    }

    private function seedSelectionOrders(User $admin, User $owner, array $warehouses, array $pickups): void
    {
        $samples = [
            ['KHO-HN', 'HCM', 'draft', 'Nguyễn Thanh An'],
            ['KHO-HN-LB', 'DN', 'submitted', 'Trần Minh Châu'],
            ['KHO-HCM', 'HN', 'approved', 'Lê Hoàng Phúc'],
            ['KHO-HCM-TD', 'DN', 'draft', 'Phạm Ngọc Mai'],
            ['KHO-DN', 'HN', 'submitted', 'Võ Gia Bảo'],
            ['KHO-DN-LC', 'HCM', 'approved', 'Đặng Thu Trang'],
        ];
        $service = app(OrderService::class);
        foreach ($samples as $index => [$warehouseCode, $destinationCode, $status, $recipient]) {
            $token = '20261105-0002-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            // Bỏ qua đơn đã có, kể cả khi người dùng đã đổi kho hoặc xử lý tiếp.
            if (Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            $warehouse = $warehouses[$warehouseCode];
            $order = $service->saveDraft($owner, [
                'client_token' => $token, 'pickup_address_id' => $pickups[$warehouse->area_id]->id, 'warehouse_id' => $warehouse->id,
                'area_id' => Area::where('code', $destinationCode)->firstOrFail()->id,
                'recipient_name' => $recipient, 'recipient_phone' => '097000010'.($index + 1),
                'recipient_address' => (20 + $index).' Đường người nhận demo',
                'weight_grams' => 500 + $index * 250, 'parcel_count' => 1,
                'service' => $index % 2 === 0 ? 'standard' : 'express',
                'cod_amount' => 150000 + $index * 50000, 'fee_payer' => $index % 2 === 0 ? 'sender' : 'recipient',
                'items' => [['name' => 'Hộp quà demo '.$warehouseCode, 'quantity' => 1]],
                'note' => 'Demo chọn kho tiếp nhận: '.$warehouseCode,
            ]);
            if ($status === 'draft') {
                continue;
            }
            $service->submit($owner, $order, $order->revision);
            if ($status === 'approved') {
                $order->refresh();
                $service->review($admin, $order, $order->revision, 'approved', 'Đã kiểm tra kho tiếp nhận khách chọn');
            }
        }
    }
}
