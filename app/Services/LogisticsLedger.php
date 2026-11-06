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
        return $order->only(['status', 'custody_status', 'assigned_shipper_id', 'revision', 'return_authorized_by', 'return_reason', 'delivery_attempt_count', 'return_attempt_count', 'delivery_attempt_limit', 'retry_authorized_attempt', 'financial_hold', 'delivered_at', 'returned_at']) + [
            'warehouse_id' => $order->warehouse_id, 'origin_warehouse_id' => $order->origin_warehouse_id,
            'parcels' => WarehouseItem::where('order_id', $order->id)->orderBy('parcel_number')->lockForUpdate()->get()->map->only(['id', 'parcel_number', 'holder_type', 'holder_user_id', 'warehouse_id', 'warehouse_location_id', 'warehouse_transfer_id', 'custody_version', 'actual_weight_grams', 'condition'])->all(),
            'incident_holds' => $order->incidents()->where(fn ($q) => $q->where('blocks_dispatch', true)->orWhere('blocks_reconciliation', true))->lockForUpdate()->get()->map->only(['id', 'blocks_dispatch', 'blocks_reconciliation', 'financial_status'])->all(),
        ];
    }

    public function record(User $actor, Order $order, string $event, array $before, string $reason, array $context = []): void
    {
        $holders = WarehouseItem::where('order_id', $order->id)->lockForUpdate()->get(['holder_type'])->pluck('holder_type')->unique();
        $order->custody_status = $holders->isEmpty() ? 'uncollected' : ($holders->count() === 1 ? $holders->first() : 'mixed');
        $order->revision++;
        $order->save();
        $after = array_merge($this->snapshot($order), $context);
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

    public function ensureNoIncidentDispatchHold(Order $order): void
    {
        $this->ensure(! $order->incidents()->blockingDispatch()->exists(), 'Vận đơn đang khóa vận chuyển do sự cố hoặc kiện đã xác nhận mất.');
    }
}
