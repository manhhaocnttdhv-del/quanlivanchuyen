<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;

class ParcelLossService
{
    public function __construct(private LogisticsLedger $ledger, private AuditService $audit) {}

    // Called under the order lock, in the same transaction as independent incident approval.
    public function confirm(User $actor, Order $order, Incident $incident): void
    {
        $items = $order->parcels()->when($incident->warehouse_item_id, fn ($q, $id) => $q->whereKey($id))->lockForUpdate()->get();
        $this->ledger->ensure($items->isNotEmpty() && $items->every(fn ($item) => in_array($item->holder_type, ['warehouse', 'shipper', 'transfer'])),
            'Chỉ xác nhận mất các kiện đang thuộc trách nhiệm vận chuyển; không ghi mất lại hoặc đổi kiện đã bàn giao khách/shop.');
        foreach ($items as $item) {
            $item->update(['holder_type' => 'lost', 'condition' => 'lost', 'holder_user_id' => null, 'warehouse_location_id' => null,
                'warehouse_transfer_id' => null, 'custody_version' => $item->custody_version + 1]);
        }
        foreach ($order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->lockForUpdate()->get() as $task) {
            $remaining = $order->parcels()->where('holder_type', 'shipper')->where('holder_user_id', $task->shipper_id)->exists();
            $task->update(['status' => $remaining ? 'recall_pending' : 'cancelled', 'finished_at' => $remaining ? null : now()]);
            $task->handovers()->where('status', 'pending')->update(['status' => 'cancelled']);
            $task->assignments()->create(['shipper_id' => $task->shipper_id, 'created_by' => $actor->id, 'event' => $task->status,
                'reason' => $incident->code.': xác nhận mất kiện; trả phần hàng còn giữ về kho.']);
            if (! $remaining) {
                $order->assigned_shipper_id = null;
            }
        }
        foreach ($order->transfers()->whereIn('status', ['in_transit', 'partial'])->lockForUpdate()->get() as $transfer) {
            $before = $transfer->only(['status', 'source_warehouse_id', 'destination_warehouse_id']);
            $transfer->lines()->whereIn('warehouse_item_id', $items->modelKeys())->whereNull('received_at')
                ->update(['condition' => 'lost', 'receipt_reason' => $incident->code.': kết luận mất kiện đã duyệt.']);
            $pending = $transfer->lines()->whereNull('received_at')->where(fn ($q) => $q->whereNull('condition')->orWhere('condition', '!=', 'lost'))->exists();
            $transfer->update(['status' => $pending ? 'partial' : 'closed_loss']);
            $this->audit->record($actor, $transfer, 'transfer.loss_recorded', $before, $transfer->only(['status']) + ['incident_id' => $incident->id], $incident->code.': kết luận mất kiện đã duyệt.');
            if (! $pending && $order->parcels()->where('holder_type', 'warehouse')->where('warehouse_id', $transfer->destination_warehouse_id)->exists()) {
                $order->warehouse_id = $transfer->destination_warehouse_id;
                $order->status = 'at_warehouse';
            }
        }
        if (! $order->parcels()->where('holder_type', '!=', 'lost')->exists()) {
            $order->status = 'lost';
            $order->assigned_shipper_id = null;
        }
    }

    public function ensureRecovered(Order $order, Incident $incident): void
    {
        $items = $order->parcels()->when($incident->warehouse_item_id, fn ($q, $id) => $q->whereKey($id))->lockForUpdate()->get();
        $this->ledger->ensure($items->isNotEmpty() && $items->every(fn ($item) => $item->holder_type === 'warehouse'),
            'Phải tìm và nhập đủ các kiện liên quan về kho trước khi kết luận không bồi thường.');
        $this->ledger->ensure(! $order->discrepancies()->whereIn('warehouse_item_id', $items->modelKeys())->where('type', 'missing')->where('status', 'open')->exists(),
            'Sai lệch thiếu kiện phải được người khác xác minh trước khi gỡ khóa mất hàng.');
    }
}
