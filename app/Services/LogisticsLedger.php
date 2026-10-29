<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarehouseItem;
use Illuminate\Validation\ValidationException;

class LogisticsLedger
{
    public function __construct(private AuditService $audit) {}

    public function lock(Order $order): Order
    {
        // Giữ cùng thứ tự khóa với OrderService; khóa đơn là điểm tuần tự hóa mọi kiện.
        Shop::lockForUpdate()->findOrFail($order->shop_id);

        return Order::lockForUpdate()->findOrFail($order->id);
    }

    public function snapshot(Order $order): array
    {
        return $order->only(['status', 'custody_status', 'assigned_shipper_id', 'revision', 'return_authorized_by', 'return_reason']) + [
            'parcels' => WarehouseItem::where('order_id', $order->id)->orderBy('parcel_number')->lockForUpdate()->get()->map->only(['id', 'parcel_number', 'holder_type', 'holder_user_id', 'warehouse_id', 'warehouse_location_id', 'custody_version', 'actual_weight_grams', 'condition'])->all(),
        ];
    }

    public function record(User $actor, Order $order, string $event, array $before, string $reason): void
    {
        $holders = WarehouseItem::where('order_id', $order->id)->lockForUpdate()->get(['holder_type'])->pluck('holder_type')->unique();
        $order->custody_status = $holders->isEmpty() ? 'uncollected' : ($holders->count() === 1 ? $holders->first() : 'mixed');
        $order->revision++;
        $order->save();
        $after = $this->snapshot($order);
        $order->histories()->create(['event' => $event, 'created_by' => $actor->id, 'from_status' => $before['status'], 'to_status' => $order->status,
            'revision' => $order->revision, 'reason' => $reason, 'before_values' => $before, 'after_values' => $after, 'created_at' => now()]);
        $this->audit->record($actor, $order, 'logistics.'.$event, $before, $after, $reason);
    }

    public function ensure(bool $condition, string $message, string $field = 'reason'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
