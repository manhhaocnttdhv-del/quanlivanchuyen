<?php

namespace Database\Seeders;

use App\Models\FeeVersion;
use App\Models\Order;
use App\Models\Role;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\IdentityService;
use App\Services\OrderService;
use App\Services\ShopService;
use App\Services\StocktakeService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoWebsiteSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(DemoShippingSeeder::class);
            $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
            $branches = [
                ['hn', 'Hà Nội', 'Mây Boutique', ['KHO-HN', 'KHO-HN-LB']],
                ['hcm', 'TP.HCM', 'Sách An Nhiên', ['KHO-HCM', 'KHO-HCM-TD']],
                ['dn', 'Đà Nẵng', 'Quà Miền Trung', ['KHO-DN', 'KHO-DN-LC']],
            ];
            foreach ($branches as $index => [$code, $name, $shopName, $warehouseCodes]) {
                $warehouses = Warehouse::whereIn('code', $warehouseCodes)->get()->keyBy('code');
                $operator = User::where('email', 'operations.'.$code.'@guiviet.test')->firstOrFail();
                $shipper = $this->seedShipper($admin, $code, $name, $index, $warehouses->pluck('id')->all());
                $owner = $this->seedShop($admin, 'shop.'.$code, $shopName, '093000030'.($index + 1),
                    $warehouses->first(), 'approved', $index !== 2);
                foreach ($warehouseCodes as $offset => $warehouseCode) {
                    $warehouse = $warehouses->get($warehouseCode);
                    $this->seedOrders($owner, $operator, $shipper, $warehouse, $index * 2 + $offset + 1);
                    $this->seedStocktakes($admin, $operator, $warehouse);
                }
            }
            $warehouse = Warehouse::where('code', 'KHO-HN')->firstOrFail();
            $this->seedShop($admin, 'rejected', 'Shop hồ sơ cần xác minh', '0930000401', $warehouse, 'rejected', false);
            $this->seedShop($admin, 'suspended', 'Shop tạm ngừng nhận đơn', '0930000402', $warehouse, 'suspended', false);
            $this->seedDraftFee($admin);
        }, 3);
    }

    private function seedShipper(User $admin, string $code, string $name, int $index, array $warehouseIds): User
    {
        $email = 'shipper.'.$code.'@guiviet.test';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }

        return app(IdentityService::class)->save($admin, [
            'name' => 'Shipper chi nhánh '.$name, 'email' => $email, 'phone' => '093000020'.($index + 1),
            'password' => env('DEMO_PASSWORD'), 'status' => 'active',
            'role_ids' => [Role::where('name', 'shipper')->firstOrFail()->id], 'warehouse_ids' => $warehouseIds,
            'reason' => 'Tạo shipper demo theo chi nhánh '.$name,
        ]);
    }

    private function seedShop(User $admin, string $account, string $name, string $phone, Warehouse $warehouse, string $status, bool $verifyBank): User
    {
        $email = $account.'@guiviet.test';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return $existing;
        }
        $service = app(ShopService::class);
        $owner = $service->register([
            'name' => 'Chủ '.$name, 'email' => $email, 'phone' => $phone, 'password' => env('DEMO_PASSWORD'),
            'shop_name' => $name, 'area_id' => $warehouse->area_id, 'address' => '25 Đường cửa hàng mẫu, '.$warehouse->area->name,
        ]);
        $service->replaceBank($owner, $owner->shop, [
            'bank_name' => 'Ngân hàng demo', 'holder_name' => 'CHU CUA HANG DEMO', 'account_number' => '000'.$phone,
        ]);
        if ($verifyBank) {
            $service->verifyBank($admin, $owner->shop->fresh()->currentBankAccount, 'Demo toàn web: đã kiểm tra tài khoản ngân hàng');
        }
        if ($status === 'suspended') {
            $service->review($admin, $owner->shop, ['status' => 'approved', 'reason' => 'Demo toàn web: hồ sơ đã được duyệt']);
        }
        $service->review($admin, $owner->shop, [
            'status' => $status, 'reason' => 'Demo toàn web: '.match ($status) {
                'approved' => 'hồ sơ cửa hàng hợp lệ',
                'rejected' => 'thông tin người đại diện chưa khớp hồ sơ',
                'suspended' => 'tạm ngừng theo đề nghị của cửa hàng',
            },
        ]);

        return $owner;
    }

    private function seedOrders(User $owner, User $operator, User $shipper, Warehouse $warehouse, int $warehouseIndex): void
    {
        $scenarios = [
            'draft', 'submitted', 'needs_info', 'rejected', 'cancelled', 'change_pending', 'waiting',
            'pickup', 'pickup_failed', 'inbound', 'stocked', 'damaged', 'weight_review', 'outbound', 'on_route', 'recall', 'returning',
        ];
        $recipients = ['Nguyễn Minh Anh', 'Trần Hoàng Nam', 'Lê Thu Hà', 'Phạm Gia Huy', 'Võ Bảo Ngọc', 'Đặng Khánh Linh'];
        $goods = ['Áo thun cotton', 'Sách và vở', 'Túi vải', 'Đồ dùng học tập', 'Hộp quà', 'Phụ kiện tóc'];
        $pickup = $owner->shop->addresses()->where('area_id', $warehouse->area_id)->firstOrFail();
        $service = app(OrderService::class);
        foreach ($scenarios as $index => $scenario) {
            $token = sprintf('20261105-0004-4000-8000-%012d', $warehouseIndex * 100 + $index + 1);
            // Token cố định giữ nguyên cả các đơn đã được xử lý tiếp sau lần seed trước.
            if (Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            $order = $service->saveDraft($owner, [
                'client_token' => $token, 'pickup_address_id' => $pickup->id, 'warehouse_id' => $warehouse->id,
                'area_id' => $warehouse->area_id, 'recipient_name' => $recipients[$index % count($recipients)],
                'recipient_phone' => '096'.str_pad((string) ($warehouseIndex * 100 + $index), 7, '0', STR_PAD_LEFT),
                'recipient_address' => (40 + $index).' Đường người nhận mẫu, '.$warehouse->area->name,
                'weight_grams' => 1000, 'parcel_count' => 2, 'service' => $index % 3 === 0 ? 'express' : 'standard',
                'cod_amount' => $index % 4 === 0 ? 0 : 150000 + $index * 25000,
                'fee_payer' => $index % 2 === 0 ? 'sender' : 'recipient',
                'items' => [['name' => $goods[$index % count($goods)], 'quantity' => 2]],
                'note' => 'Demo toàn web: '.$scenario.' / '.$warehouse->code,
            ]);
            $this->advanceOrder($owner, $operator, $shipper, $order, $scenario);
        }
    }

    private function advanceOrder(User $owner, User $operator, User $shipper, Order $order, string $scenario): void
    {
        if ($scenario === 'draft') {
            return;
        }
        $orders = app(OrderService::class);
        $orders->submit($owner, $order, $order->revision);
        $order->refresh();
        if ($scenario === 'submitted') {
            return;
        }
        if ($scenario === 'cancelled') {
            $orders->cancel($owner, $order, $order->revision, 'Demo toàn web: khách hủy trước khi tiếp nhận kiện');

            return;
        }
        if (in_array($scenario, ['needs_info', 'rejected'])) {
            $orders->review($operator, $order, $order->revision, $scenario, 'Demo toàn web: cần xác minh số nhà và người nhận');

            return;
        }
        $orders->review($operator, $order, $order->revision, 'approved', 'Demo toàn web: thông tin hợp lệ');
        $order->refresh();
        if ($scenario === 'change_pending') {
            $orders->requestChange($owner, $order, $order->revision, 'edit',
                array_merge($order->inputData(), ['cod_amount' => (int) $order->cod_amount + 25000]),
                'Demo toàn web: khách bổ sung một sản phẩm, chờ duyệt COD');

            return;
        }
        if ($scenario === 'waiting') {
            return;
        }
        $dispatch = app(DispatchService::class);
        $stock = app(WarehouseService::class);
        $proof = 'logistics-evidence/demo-warehouse.jpg';
        $task = $dispatch->assign($operator, $order, [
            'revision' => $order->revision, 'type' => 'pickup', 'shipper_id' => $shipper->id, 'reason' => 'Demo toàn web: phân công lấy hàng',
        ]);
        if ($scenario === 'pickup') {
            return;
        }
        $dispatch->pickup($shipper, $task, [
            'successful' => $scenario !== 'pickup_failed', 'condition' => 'good',
            'reason' => $scenario === 'pickup_failed' ? 'Demo toàn web: shop hẹn lấy lại ngày mai' : 'Demo toàn web: lấy đủ hai kiện',
        ], $proof);
        if ($scenario === 'pickup_failed') {
            return;
        }
        $doc = $stock->prepare($shipper, $task, 'inbound', 'Demo toàn web: shipper bàn giao về kho');
        if ($scenario === 'inbound') {
            return;
        }
        $locationId = WarehouseLocation::where('warehouse_id', $order->warehouse_id)
            ->where('code', $scenario === 'damaged' ? 'B-01' : 'A-01')->firstOrFail()->id;
        $firstLineId = $doc->lines->first()->id;
        $lines = $doc->lines->mapWithKeys(fn ($line) => [$line->id => [
            'received' => true, 'condition' => $scenario === 'damaged' && $line->id === $firstLineId ? 'damaged' : 'good',
            'weight_grams' => $scenario === 'weight_review' ? 600 : 500,
        ]])->all();
        $stock->confirm($operator, $doc, [
            'lines' => $lines, 'location_id' => $locationId, 'reason' => 'Demo toàn web: kiểm đếm và cân tại kho',
        ], $proof);
        if (in_array($scenario, ['stocked', 'damaged'])) {
            return;
        }
        if ($scenario === 'weight_review') {
            $stock->requestWeightReview($operator, $order->discrepancies()->where('type', 'weight')->firstOrFail(),
                'Demo toàn web: cân thực tế 1.200g, đề nghị người khác duyệt lại cước');

            return;
        }
        if ($scenario === 'returning') {
            $dispatch->authorizeReturn($operator, $order->fresh(), 'Demo toàn web: shop yêu cầu hoàn, đã xác nhận qua điện thoại');
        }
        $order->refresh();
        $task = $dispatch->assign($operator, $order, [
            'revision' => $order->revision, 'type' => $scenario === 'returning' ? 'return' : 'delivery',
            'shipper_id' => $shipper->id, 'reason' => 'Demo toàn web: phân công giao hoặc hoàn theo quyết định',
        ]);
        $doc = $stock->prepare($operator, $task, 'outbound', 'Demo toàn web: lập phiếu xuất hai kiện');
        if ($scenario === 'outbound') {
            return;
        }
        $lines = $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all();
        $stock->confirm($shipper, $doc, ['lines' => $lines, 'reason' => 'Demo toàn web: shipper nhận đủ hai kiện'], $proof);
        if ($scenario === 'recall') {
            $dispatch->recall($operator, $task->fresh(), 'Demo toàn web: đổi tuyến, yêu cầu shipper bàn giao lại về kho');
        }
    }

    private function seedStocktakes(User $admin, User $operator, Warehouse $warehouse): void
    {
        $service = app(StocktakeService::class);
        foreach (['draft', 'submitted', 'approved'] as $status) {
            $reason = 'Demo toàn web: kiểm kê '.$status.' / '.$warehouse->code;
            if (Stocktake::where('reason', $reason)->exists()) {
                continue;
            }
            $stocktake = $service->start($operator, ['warehouse_id' => $warehouse->id, 'reason' => $reason]);
            if ($status === 'draft') {
                continue;
            }
            // Chỉ tạo sai lệch mẫu trên kiện của bộ demo này, không giữ hàng đã có của khách.
            $missingLineId = $stocktake->lines()->whereHas('item.order', fn ($query) => $query
                ->where('client_token', 'like', '20261105-0004-4000-8000-%'))->value('id');
            $lines = $stocktake->lines->mapWithKeys(fn ($line) => [$line->id => [
                'actual_present' => $status !== 'approved' || $line->id !== $missingLineId,
                'note' => $status === 'approved' && $line->id === $missingLineId ? 'Demo: chưa tìm thấy kiện, cần kiểm tra vị trí' : 'Demo: đã kiểm đếm thực tế',
            ]])->all();
            $service->submit($operator, $stocktake, ['lines' => $lines]);
            if ($status === 'approved') {
                $service->approve($admin, $stocktake, 'Demo toàn web: xác nhận kết quả, giữ sổ tồn và mở sai lệch cần xác minh');
            }
        }
    }

    private function seedDraftFee(User $admin): void
    {
        $name = 'Biểu phí demo dự thảo';
        if (FeeVersion::where('name', $name)->exists()) {
            return;
        }
        $published = FeeVersion::with('rules')->where('status', 'published')->firstOrFail();
        app(FeeVersionService::class)->save($admin, [
            'name' => $name, 'active_from' => now()->addMonth()->format('Y-m-d\TH:i'), 'active_until' => null,
            'rules' => $published->rules->map->only([
                'route', 'service', 'min_weight_grams', 'max_weight_grams', 'base_weight_grams', 'step_weight_grams', 'base_fee', 'step_fee', 'max_cod',
            ])->all(),
        ]);
    }
}
