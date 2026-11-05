<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DispatchService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoNotificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $dispatcher = User::where('email', 'operations@guiviet.test')->firstOrFail();
        $shipper = User::where('email', 'shipper@guiviet.test')->firstOrFail();
        $warehouse = Warehouse::where('code', 'KHO-HN')->firstOrFail();
        foreach (['submitted', 'assigned'] as $index => $scenario) {
            $token = '00000008-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            $existing = Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->first();
            if ($existing) {
                $this->command?->info($scenario.': '.$existing->tracking_code.' (đã có)');

                continue;
            }
            // Seed qua Service va commit that de kiem tra outbox/queue, khong tao thong bao gia.
            $order = DB::transaction(function () use ($owner, $dispatcher, $shipper, $warehouse, $scenario, $token) {
                $orders = app(OrderService::class);
                $order = $orders->saveDraft($owner, [
                    'client_token' => $token, 'pickup_address_id' => $owner->shop->addresses()->where('is_active', true)->value('id'),
                    'warehouse_id' => $warehouse->id,
                    'area_id' => Area::where('code', 'HN')->value('id'), 'recipient_name' => 'Khách demo thông báo',
                    'recipient_phone' => '0987654321', 'recipient_address' => '80 Nguyễn Trãi, Hà Nội',
                    'weight_grams' => 1000, 'parcel_count' => 1, 'service' => 'standard', 'cod_amount' => 500000,
                    'fee_payer' => 'sender', 'note' => 'Demo Sprint 8: '.$scenario,
                    'items' => [['name' => 'Sách demo nhật ký', 'quantity' => 1]],
                ]);
                $orders->submit($owner, $order, $order->revision);
                if ($scenario === 'assigned') {
                    $orders->review($dispatcher, $order->fresh(), $order->fresh()->revision, 'approved', 'Demo Sprint 8: duyệt đủ thông tin');
                    app(DispatchService::class)->assign($dispatcher, $order->fresh(), [
                        'revision' => $order->fresh()->revision, 'type' => 'pickup', 'shipper_id' => $shipper->id,
                        'reason' => 'Demo Sprint 8: phân công lấy hàng để kiểm tra thông báo shipper',
                    ]);
                }

                return $order;
            });
            $this->command?->info($scenario.': '.$order->tracking_code);
        }
        $this->command?->info('Chạy worker notifications rồi đăng nhập điều phối, shop và shipper để kiểm tra.');
    }
}
