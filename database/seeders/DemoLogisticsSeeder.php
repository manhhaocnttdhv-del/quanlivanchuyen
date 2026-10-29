<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\DispatchService;
use App\Services\OrderService;
use App\Services\StocktakeService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoLogisticsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $dispatcher = User::where('email', 'dispatcher@guiviet.test')->firstOrFail();
        $clerk = User::where('email', 'warehouse@guiviet.test')->firstOrFail();
        $shipper = User::where('email', 'shipper@guiviet.test')->firstOrFail();
        $warehouse = Warehouse::where('code', 'KHO-HN')->firstOrFail();
        $location = WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'A-01'], ['name' => 'Kệ hàng chờ giao A-01']);
        WarehouseLocation::firstOrCreate(['warehouse_id' => $warehouse->id, 'code' => 'B-01'], ['name' => 'Khu kiểm tra B-01']);
        // Ảnh minh họa có sẵn, không phải chứng cứ giao nhận thực tế.
        $proof = 'logistics-evidence/demo-warehouse.jpg';
        Storage::disk('local')->put($proof, file_get_contents(public_path('images/logistics-warehouse.jpg')));
        $orders = app(OrderService::class);
        $dispatch = app(DispatchService::class);
        $stock = app(WarehouseService::class);
        $scenarios = ['waiting', 'pickup', 'inbound', 'stocked', 'damaged', 'outbound', 'on_route'];
        foreach ($scenarios as $index => $scenario) {
            $token = '00000003-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            if (Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            DB::transaction(function () use ($owner, $dispatcher, $clerk, $shipper, $orders, $dispatch, $stock, $scenario, $index, $token, $location, $proof) {
                $order = $orders->saveDraft($owner, ['client_token' => $token, 'pickup_address_id' => $owner->shop->addresses()->where('is_active', true)->value('id'),
                    'area_id' => Area::where('code', 'HN')->value('id'), 'recipient_name' => ['Minh Hạnh', 'Hoàng Phúc', 'Thu Thảo', 'Ngọc Mai', 'Tuấn Anh', 'Bảo Trâm', 'Gia Khánh'][$index],
                    'recipient_phone' => '098765'.(6700 + $index), 'recipient_address' => (30 + $index).' Đường mẫu, Hà Nội', 'weight_grams' => 1000, 'parcel_count' => 2,
                    'service' => 'standard', 'cod_amount' => 250000 + $index * 50000, 'fee_payer' => 'recipient', 'note' => 'Demo Sprint 3: '.$scenario,
                    'items' => [['name' => 'Sách và phụ kiện demo', 'quantity' => 2]]]);
                $orders->submit($owner, $order, $order->revision);
                $orders->review($dispatcher, $order->fresh(), $order->fresh()->revision, 'approved', 'Demo: kiểm tra và duyệt thông tin');
                if ($scenario === 'waiting') {
                    return;
                }
                $task = $dispatch->assign($dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => 'pickup', 'shipper_id' => $shipper->id, 'reason' => 'Demo: tuyến lấy hàng nội thành']);
                if ($scenario === 'pickup') {
                    return;
                }
                $dispatch->pickup($shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Demo: đã lấy đủ 2 kiện tại shop'], $proof);
                $doc = $stock->prepare($shipper, $task, 'inbound', 'Demo: bàn giao từ shipper về kho');
                if ($scenario === 'inbound') {
                    return;
                }
                $lines = $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => $scenario === 'damaged' && $line->id === $doc->lines->first()->id ? 'damaged' : 'good', 'weight_grams' => 500]])->all();
                $stock->confirm($clerk, $doc, ['lines' => $lines, 'location_id' => $location->id, 'reason' => 'Demo: kiểm đếm, cân thực tế từng kiện'], $proof);
                if (in_array($scenario, ['stocked', 'damaged'])) {
                    return;
                }
                $task = $dispatch->assign($dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => 'delivery', 'shipper_id' => $shipper->id, 'reason' => 'Demo: phân công giao tuyến nội thành']);
                $doc = $stock->prepare($clerk, $task, 'outbound', 'Demo: kho giao đủ 2 kiện cho shipper');
                if ($scenario === 'on_route') {
                    $lines = $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all();
                    $stock->confirm($shipper, $doc, ['lines' => $lines, 'reason' => 'Demo: shipper xác nhận đủ 2 kiện'], $proof);
                }
            });
        }
        if (! Stocktake::where('reason', 'Demo Sprint 3: kiểm kê chờ điều phối xác nhận')->exists()) {
            $service = app(StocktakeService::class);
            $stocktake = $service->start($clerk, ['warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'reason' => 'Demo Sprint 3: kiểm kê chờ điều phối xác nhận']);
            $lines = $stocktake->lines->mapWithKeys(fn ($line) => [$line->id => ['actual_present' => true, 'note' => 'Đã đếm tại kệ demo']])->all();
            $service->submit($clerk, $stocktake, ['lines' => $lines]);
        }
    }
}
