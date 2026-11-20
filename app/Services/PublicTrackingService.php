<?php

namespace App\Services;

use App\Models\Order;

class PublicTrackingService
{
    public function track(string $code, string $suffix): array
    {
        $order = Order::where('tracking_code', $code)->whereNotNull('submitted_at')->first();
        if (! $order || ! hash_equals(substr((string) $order->recipient_phone, -4), $suffix)) {
            return ['found' => false, 'message' => 'Chưa tìm thấy đơn hàng khớp thông tin xác minh.'];
        }

        // Chỉ công bố trạng thái vận chuyển; không đưa actor, lý do hay lịch sử nội bộ ra ngoài.
        return ['found' => true, 'code' => $order->tracking_code, 'status' => Order::STATUSES[$order->status] ?? 'Đang xử lý',
            'message' => 'Cập nhật lúc '.$order->updated_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y').'.',
            'events' => $order->histories()->whereIn('to_status', ['submitted', 'approved', 'cancelled', 'picked_up', 'at_warehouse', 'in_transit', 'out_for_delivery', 'delivered', 'delivery_failed', 'returning', 'returned', 'return_failed', 'lost'])
                ->whereColumn('from_status', '!=', 'to_status')->get()->map(fn ($history) => [
                    'status' => Order::STATUSES[$history->to_status], 'time' => $history->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'),
                ])->all()];
    }
}
