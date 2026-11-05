<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\OrderService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DemoDeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $operator = User::where('email', 'operations@guiviet.test')->firstOrFail();
        $shipper = User::where('email', 'shipper@guiviet.test')->firstOrFail();
        $location = WarehouseLocation::whereHas('warehouse', fn ($q) => $q->where('code', 'KHO-HN'))->where('code', 'A-01')->firstOrFail();
        $orders = app(OrderService::class);
        $dispatch = app(DispatchService::class);
        $stock = app(WarehouseService::class);
        $delivery = app(DeliveryService::class);
        $photo = fn () => new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'demo.jpg', 'image/jpeg', null, true);
        $assign = fn ($order, $type) => $dispatch->assign($operator, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => $type, 'shipper_id' => $shipper->id, 'reason' => 'Demo Sprint 4: phân công tuyến nội thành']);
        $inbound = function ($task) use ($shipper, $operator, $stock, $location) {
            $doc = $stock->prepare($shipper, $task, 'inbound', 'Demo: trả đủ hai kiện về kho');
            $stock->confirm($operator, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all(), 'location_id' => $location->id, 'reason' => 'Demo: đã đếm đủ hai kiện'], null);
        };
        $outbound = function ($task) use ($shipper, $operator, $stock) {
            $doc = $stock->prepare($operator, $task, 'outbound', 'Demo: xuất đủ kiện cho shipper');
            $stock->confirm($shipper, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all(), 'reason' => 'Demo: shipper nhận đủ hàng'], null);
        };
        foreach (['on_route', 'delivered', 'failed', 'retry', 'returning', 'returned', 'disputed'] as $index => $scenario) {
            $token = '00000004-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            if (Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            DB::transaction(function () use ($owner, $operator, $shipper, $location, $orders, $dispatch, $delivery, $assign, $inbound, $outbound, $photo, $scenario, $index, $token) {
                $order = $orders->saveDraft($owner, ['client_token' => $token, 'pickup_address_id' => $owner->shop->addresses()->where('is_active', true)->value('id'),
                    'warehouse_id' => $location->warehouse_id,
                    'area_id' => Area::where('code', 'HN')->value('id'), 'recipient_name' => ['Hà Linh', 'Tuấn Minh', 'Ngọc Ánh', 'Hồng Hà', 'Phương Thảo', 'Đức Anh', 'Minh Châu'][$index],
                    'recipient_phone' => '098765'.(6800 + $index), 'recipient_address' => (48 + $index).' Nguyễn Trãi, Hà Nội', 'weight_grams' => 1000, 'parcel_count' => 2,
                    'service' => 'standard', 'cod_amount' => 300000 + $index * 50000, 'fee_payer' => 'recipient', 'note' => 'Demo Sprint 4: '.$scenario,
                    'items' => [['name' => 'Sách và phụ kiện demo', 'quantity' => 2]]]);
                $orders->submit($owner, $order, $order->revision);
                $orders->review($operator, $order->fresh(), $order->fresh()->revision, 'approved', 'Demo: đủ thông tin gửi hàng');
                $task = $assign($order, 'pickup');
                $dispatch->pickup($shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Demo: đã lấy đủ hàng'], 'logistics-evidence/demo-warehouse.jpg');
                $inbound($task);
                $task = $assign($order, 'delivery');
                $outbound($task);
                if ($scenario === 'on_route') {
                    return;
                }
                if (in_array($scenario, ['delivered', 'disputed'])) {
                    $delivery->record($shipper, $task, ['client_token' => $token, 'outcome' => 'success', 'receiver_name' => $order->recipient_name,
                        'actual_cod' => (int) $order->cod_amount - ($scenario === 'disputed' ? 10000 : 0), 'actual_fee' => (int) $order->fresh()->shipping_fee,
                        'financial_reason' => $scenario === 'disputed' ? 'Demo: khách trả thiếu 10.000đ, chờ xác minh' : null,
                        'reason' => 'Demo: ảnh kho minh họa, không phải bằng chứng thực tế'], $photo());

                    return;
                }
                $delivery->record($shipper, $task, ['client_token' => $token, 'outcome' => 'failed', 'reason_code' => 'refused', 'reason' => 'Demo: khách từ chối nhận, đã liên hệ shop'], null);
                if ($scenario === 'failed') {
                    return;
                }
                $inbound($task);
                app(DeliveryDecisionService::class)->decide($operator, $order, ['revision' => $order->fresh()->revision, 'decision' => $scenario === 'retry' ? 'retry' : 'return',
                    'shop_instruction' => 'Demo: shop đã đồng ý phương án', 'fee_payment' => 'reconciliation', 'reason' => 'Demo: điều phối xác minh yêu cầu shop']);
                if ($scenario === 'retry') {
                    return;
                }
                $task = $assign($order, 'return');
                $outbound($task);
                if ($scenario === 'returned') {
                    $delivery->record($shipper, $task, ['client_token' => '00000004-0001-4000-8000-000000000006', 'outcome' => 'success', 'receiver_name' => $owner->name,
                        'actual_fee' => 0, 'reason' => 'Demo: shop nhận đủ hàng; ảnh kho chỉ minh họa'], $photo());
                }
            });
        }
    }
}
