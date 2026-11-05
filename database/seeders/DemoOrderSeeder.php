<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use Illuminate\Database\Seeder;

class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo vận đơn demo ở local/testing.');
        }
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
        $warehouse = Warehouse::where('code', 'KHO-HN')->firstOrFail();
        $service = app(OrderService::class);
        $samples = [
            ['Nguyễn Minh Anh', 'HN', 'Áo thun cotton', 500, 285000, 'approved', 'recipient'],
            ['Trần Hoàng Nam', 'HCM', 'Sách và vở', 1500, 420000, 'submitted', 'sender'],
            ['Lê Thu Hà', 'HCM', 'Túi vải', 501, 150000, 'needs_info', 'recipient'],
            ['Phạm Gia Huy', 'HN', 'Đồ dùng học tập', 1000, 350000, 'approved', 'sender'],
            ['Võ Bảo Ngọc', 'HCM', 'Áo khoác', 1200, 590000, 'submitted', 'recipient'],
            ['Đặng Khánh Linh', 'HN', 'Phụ kiện tóc', 200, 95000, 'draft', 'sender'],
            ['Bùi Đức Long', 'HCM', 'Bình nước', 800, 180000, 'cancelled', 'recipient'],
            ['Hoàng Mai Chi', 'HN', 'Hộp quà', 700, 260000, 'change', 'recipient'],
        ];
        foreach ($samples as $i => [$name, $destination, $goods, $weight, $cod, $status, $payer]) {
            $token = '00000000-0000-4000-8000-'.str_pad((string) ($i + 1), 12, '0', STR_PAD_LEFT);
            if (Order::where('shop_id', $owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            $order = $service->saveDraft($owner, [
                'client_token' => $token, 'pickup_address_id' => $owner->shop->addresses()->where('is_active', true)->value('id'),
                'warehouse_id' => $warehouse->id,
                'area_id' => Area::where('code', $destination)->value('id'), 'recipient_name' => $name,
                'recipient_phone' => '098765'.str_pad((string) (6781 + $i), 4, '0', STR_PAD_LEFT),
                'recipient_address' => (20 + $i).' Đường demo, phường mẫu', 'weight_grams' => $weight, 'parcel_count' => $i === 1 ? 2 : 1,
                'service' => $i === 4 ? 'express' : 'standard', 'cod_amount' => $cod, 'fee_payer' => $payer,
                'items' => [['name' => $goods, 'quantity' => $i + 1]], 'note' => 'Dữ liệu mẫu phục vụ đồ án',
            ]);
            if ($status === 'draft') {
                continue;
            }
            $service->submit($owner, $order, $order->revision);
            $order->refresh();
            if (in_array($status, ['approved', 'change'])) {
                $service->review($admin, $order, $order->revision, 'approved', 'Thông tin hợp lệ, chờ phân công lấy hàng');
                $order->refresh();
            }
            if ($status === 'needs_info') {
                $service->review($admin, $order, $order->revision, 'needs_info', 'Shop bổ sung số nhà và người liên hệ tại địa chỉ nhận');
            }
            if ($status === 'cancelled') {
                $service->cancel($owner, $order, $order->revision, 'Khách đổi ý trước khi tiếp nhận kiện');
            }
            if ($status === 'change') {
                $service->requestChange($owner, $order, $order->revision, 'edit', array_merge($order->inputData(), ['cod_amount' => 310000]), 'Khách bổ sung một món vào hộp quà');
            }
        }
    }
}
