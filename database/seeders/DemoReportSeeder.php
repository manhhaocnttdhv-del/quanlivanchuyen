<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\OrderService;
use App\Services\WarehouseService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DemoReportSeeder extends Seeder
{
    private const TOKEN = '00000007-0000-4000-8000-000000000001';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }

        $this->call(CatalogSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $order = Order::where('shop_id', $owner->shop->id)->where('client_token', self::TOKEN)->first();
        if (! $order) {
            $order = $this->createScenario($owner);
        }

        $this->command?->info($order->tracking_code.': tạo '.$order->created_at->format('Y-m-d').', giao '.$order->delivered_at->format('Y-m-d'));
        $this->command?->info('Demo kỳ '.$order->delivered_at->format('Y-m-d').': đơn này có trong Giao thành công, không có trong Đơn tạo trong kỳ.');
    }

    private function createScenario(User $owner): Order
    {
        $operator = User::where('email', 'operations@guiviet.test')->firstOrFail();
        $shipper = User::where('email', 'shipper@guiviet.test')->firstOrFail();
        $location = WarehouseLocation::whereHas('warehouse', fn ($q) => $q->where('code', 'KHO-HN'))
            ->where('code', 'A-01')->firstOrFail();
        $deliveredAt = now()->startOfMonth();
        $createdAt = $deliveredAt->copy()->subDay()->setTime(10, 0);
        $previousClock = Carbon::getTestNow();

        try {
            return DB::transaction(function () use ($owner, $operator, $shipper, $location, $createdAt, $deliveredAt) {
                // Tao lich su qua Service tai tung moc gio; khong sua nguoc chung tu bat bien.
                Carbon::setTestNow($createdAt);
                $orders = app(OrderService::class);
                $dispatch = app(DispatchService::class);
                $stock = app(WarehouseService::class);
                $order = $this->draft($owner, $location->warehouse_id);
                $orders->submit($owner, $order, $order->revision);
                $orders->review($operator, $order->fresh(), $order->fresh()->revision, 'approved', 'Demo: duyệt đơn cuối tháng');

                $pickup = $dispatch->assign($operator, $order->fresh(), [
                    'revision' => $order->fresh()->revision, 'type' => 'pickup',
                    'shipper_id' => $shipper->id, 'reason' => 'Demo: lấy hàng cuối tháng',
                ]);
                $dispatch->pickup($shipper, $pickup, [
                    'successful' => true, 'condition' => 'good', 'reason' => 'Demo: đã lấy đủ kiện',
                ], 'logistics-evidence/demo-warehouse.jpg');
                $inbound = $stock->prepare($shipper, $pickup, 'inbound', 'Demo: nhập kho cuối tháng');
                $stock->confirm($operator, $inbound, [
                    'lines' => $inbound->lines->mapWithKeys(fn ($line) => [$line->id => [
                        'received' => true, 'condition' => 'good', 'weight_grams' => 1000,
                    ]])->all(),
                    'location_id' => $location->id, 'reason' => 'Demo: kiểm đếm đủ hàng',
                ], null);

                Carbon::setTestNow($deliveredAt);
                $delivery = $dispatch->assign($operator, $order->fresh(), [
                    'revision' => $order->fresh()->revision, 'type' => 'delivery',
                    'shipper_id' => $shipper->id, 'reason' => 'Demo: giao sang tháng tiếp theo',
                ]);
                $outbound = $stock->prepare($operator, $delivery, 'outbound', 'Demo: xuất đủ kiện để giao');
                $stock->confirm($shipper, $outbound, [
                    'lines' => $outbound->lines->mapWithKeys(fn ($line) => [$line->id => [
                        'received' => true, 'condition' => 'good',
                    ]])->all(),
                    'reason' => 'Demo: shipper nhận đủ kiện',
                ], null);
                app(DeliveryService::class)->record($shipper, $delivery, [
                    'client_token' => self::TOKEN, 'outcome' => 'success', 'receiver_name' => 'Khách demo báo cáo',
                    'actual_cod' => 500000, 'actual_fee' => 0,
                    'reason' => 'Demo: giao đơn tháng trước; ảnh kho chỉ minh họa',
                ], new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'demo-minh-hoa.jpg', 'image/jpeg', null, true));

                return $order->fresh();
            });
        } finally {
            Carbon::setTestNow($previousClock);
        }
    }

    private function draft(User $owner, int $warehouseId): Order
    {
        return app(OrderService::class)->saveDraft($owner, [
            'client_token' => self::TOKEN,
            'pickup_address_id' => $owner->shop->addresses()->where('is_active', true)->value('id'),
            'warehouse_id' => $warehouseId,
            'area_id' => Area::where('code', 'HN')->value('id'),
            'recipient_name' => 'Khách demo báo cáo', 'recipient_phone' => '0987654321',
            'recipient_address' => '70 Nguyễn Trãi, Hà Nội', 'weight_grams' => 1000, 'parcel_count' => 1,
            'service' => 'standard', 'cod_amount' => 500000, 'fee_payer' => 'sender',
            'note' => 'Demo Sprint 7: tạo tháng trước, giao tháng này',
            'items' => [['name' => 'Sách demo báo cáo', 'quantity' => 1]],
        ]);
    }
}
