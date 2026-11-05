<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Policies\LogisticsPolicy;
use App\Policies\WarehouseTransferPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehouseTransferService
{
    public function __construct(private LogisticsLedger $ledger, private OrderRouting $routing) {}

    public function dispatch(User $actor, Order $order, array $data): WarehouseTransfer
    {
        return DB::transaction(function () use ($actor, $order, $data) {
            $order = $this->ledger->lock($order);
            abort_unless(app(LogisticsPolicy::class)->warehouse($actor, $order->warehouse_id), 403);
            $this->ledger->ensure($order->revision === (int) $data['revision'], 'Đơn đã thay đổi; tải lại trước khi chuyển kho.', 'revision');
            $readiness = app(DispatchReadiness::class)->inspect($order);
            $this->ledger->ensure($readiness['canTransfer'], $readiness['warehouseBlockers'][0] ?? 'Đơn đã ở đúng kho để giao hoặc hoàn.');
            $this->ledger->ensure($order->status === 'at_warehouse' && $order->custody_status === 'warehouse', 'Chỉ chuyển đơn đang giữ đủ kiện tại kho.');
            $this->ledger->ensureNoIncidentDispatchHold($order);
            $this->ledger->ensure(! $order->pendingChange()->exists() && ! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists(), 'Xử lý đề nghị hoặc thu hồi nhiệm vụ trước khi chuyển kho.');
            $this->ledger->ensure(! $order->discrepancies()->where('status', 'open')->where('blocks_dispatch', true)->exists(), 'Đơn đang giữ vì sai lệch.');
            $this->ledger->ensure(! $order->transfers()->whereIn('status', ['in_transit', 'partial'])->exists(), 'Đơn đang có chuyến chuyển kho chưa nhận đủ.');
            $this->ledger->ensure(Warehouse::whereKey($order->warehouse_id)->where('is_active', true)->exists(), 'Kho gửi đang ngừng hoạt động.');
            $destination = $this->routing->destinations($order)->whereKey($data['destination_warehouse_id'])->lockForUpdate()->first();
            $this->ledger->ensure((bool) $destination, 'Chọn kho nhận thuộc khu vực người nhận; đơn hoàn phải về đúng kho gửi ban đầu.', 'destination_warehouse_id');
            $items = $order->parcels()->lockForUpdate()->get();
            $this->ledger->ensure($items->count() === $order->parcel_count && $items->every(fn ($item) => $item->holder_type === 'warehouse' && $item->warehouse_id === $order->warehouse_id), 'Phải có đủ kiện thực tế trong kho gửi.');
            $before = $this->ledger->snapshot($order);
            $transfer = $order->transfers()->create(['code' => 'CK'.Str::upper((string) Str::ulid()),
                'source_warehouse_id' => $order->warehouse_id, 'destination_warehouse_id' => $destination->id,
                'created_by' => $actor->id, 'purpose' => $order->return_authorized_by ? 'return' : 'delivery',
                'carrier' => $data['carrier'], 'transport_reference' => $data['transport_reference'], 'reason' => $data['reason'], 'dispatched_at' => now()]);
            foreach ($items as $item) {
                $item->update(['holder_type' => 'transfer', 'holder_user_id' => null, 'warehouse_location_id' => null,
                    'warehouse_transfer_id' => $transfer->id, 'custody_version' => $item->custody_version + 1]);
                $transfer->lines()->create(['warehouse_item_id' => $item->id, 'dispatched_custody_version' => $item->custody_version]);
            }
            $order->status = 'in_transit';
            $order->assigned_shipper_id = null;
            $this->ledger->record($actor, $order, 'transfer_dispatched', $before, $transfer->code.': '.$data['reason'], ['transfer' => $transfer->only(['id', 'source_warehouse_id', 'destination_warehouse_id', 'carrier', 'transport_reference', 'purpose'])]);

            return $transfer;
        }, 3);
    }

    public function receive(User $actor, WarehouseTransfer $transfer, array $data, ?string $evidence): void
    {
        $reference = $transfer->order;
        DB::transaction(function () use ($actor, $transfer, $data, $evidence, $reference) {
            $order = $this->ledger->lock($reference);
            $transfer = WarehouseTransfer::lockForUpdate()->findOrFail($transfer->id);
            abort_unless(app(WarehouseTransferPolicy::class)->receive($actor, $transfer), 403);
            $this->ledger->ensure($order->revision === (int) $data['revision'], 'Phiếu đã thay đổi; tải lại trước khi nhận.', 'revision');
            $this->ledger->ensure(in_array($transfer->status, ['in_transit', 'partial']) && $order->status === 'in_transit' && $order->warehouse_id === $transfer->source_warehouse_id, 'Chuyến chuyển đã hoàn tất hoặc đơn không còn phù hợp.');
            $location = WarehouseLocation::whereKey($data['location_id'])->where('warehouse_id', $transfer->destination_warehouse_id)->where('is_active', true)->first();
            $this->ledger->ensure((bool) $location, 'Chọn vị trí đang hoạt động của đúng kho nhận.', 'location_id');
            $lines = $transfer->lines()->whereNull('received_at')->lockForUpdate()->get();
            $provided = collect($data['lines']);
            $this->ledger->ensure($provided->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() === $lines->pluck('id')->sort()->values()->all(), 'Kiểm đếm đúng toàn bộ kiện còn chờ; không nhận lại kiện đã nhập kho.', 'lines');
            $before = $this->ledger->snapshot($order);
            foreach ($lines as $line) {
                $input = $data['lines'][$line->id];
                $item = $line->item()->lockForUpdate()->firstOrFail();
                $this->ledger->ensure($item->holder_type === 'transfer' && $item->warehouse_transfer_id === $transfer->id && $item->custody_version === $line->dispatched_custody_version, 'Người giữ kiện đã thay đổi; cần kiểm tra lại chuyến.');
                $received = (bool) $input['received'];
                $type = ! $received ? 'missing' : ($input['condition'] === 'damaged' ? 'damaged' : null);
                $this->ledger->ensure($type === null || $evidence !== null, 'Thiếu hoặc hỏng kiện phải có ảnh bằng chứng.', 'evidence');
                if ($type && ! Discrepancy::where('warehouse_transfer_id', $transfer->id)->where('warehouse_item_id', $item->id)->where('type', $type)->where('status', 'open')->exists()) {
                    Discrepancy::create(['warehouse_id' => $transfer->destination_warehouse_id, 'order_id' => $order->id,
                        'warehouse_item_id' => $item->id, 'warehouse_transfer_id' => $transfer->id, 'created_by' => $actor->id,
                        'type' => $type, 'severity' => 'high', 'reason' => $transfer->code.': '.$data['reason'], 'evidence_path' => $evidence]);
                }
                if ($received) {
                    $item->update(['holder_type' => 'warehouse', 'warehouse_id' => $transfer->destination_warehouse_id,
                        'warehouse_location_id' => $location->id, 'warehouse_transfer_id' => null,
                        'condition' => $input['condition'], 'custody_version' => $item->custody_version + 1]);
                }
                $line->update(['received_by' => $actor->id, 'received_at' => $received ? now() : null,
                    'warehouse_location_id' => $received ? $location->id : null, 'condition' => $received ? $input['condition'] : null,
                    'evidence_path' => $evidence, 'receipt_reason' => $data['reason']]);
            }
            $complete = ! $transfer->lines()->whereNull('received_at')->exists();
            $transfer->update(['status' => $complete ? 'received' : 'partial', 'received_at' => $complete ? now() : null]);
            if ($complete) {
                $order->warehouse_id = $transfer->destination_warehouse_id;
                $order->status = 'at_warehouse';
            }
            $this->ledger->record($actor, $order, $complete ? 'transfer_received' : 'transfer_partial', $before, $transfer->code.': '.$data['reason'],
                ['transfer_id' => $transfer->id, 'receipt' => $data['lines'], 'location_id' => $location->id, 'evidence_path' => $evidence]);
        }, 3);
    }
}
