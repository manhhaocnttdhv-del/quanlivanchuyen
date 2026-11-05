<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\Order;
use App\Models\User;
use App\Policies\LogisticsPolicy;
use Illuminate\Support\Facades\DB;

class DispatchService
{
    public function __construct(private LogisticsLedger $ledger, private LogisticsPolicy $policy) {}

    public function assign(User $actor, Order $order, array $data): DeliveryTask
    {
        return DB::transaction(function () use ($actor, $order, $data) {
            $order = $this->ledger->lock($order);
            abort_unless($this->policy->dispatch($actor, $order->warehouse_id), 403);
            $this->ledger->ensure($order->revision === (int) $data['revision'], 'Đơn đã thay đổi. Tải lại trước khi phân công.', 'revision');
            $readiness = app(DispatchReadiness::class)->inspect($order);
            $this->ledger->ensure($readiness['canAssign'], $readiness['blockers'][0] ?? 'Trạng thái vận đơn không đủ điều kiện phân công.');
            $shipper = User::lockForUpdate()->findOrFail($data['shipper_id']);
            $this->ledger->ensure($shipper->status === 'active' && $shipper->hasRole('shipper') && $shipper->warehouses()->whereKey($order->warehouse_id)->exists(), 'Shipper phải hoạt động và thuộc kho của đơn.', 'shipper_id');
            $type = $data['type'];
            $this->ledger->ensure(isset(DeliveryTask::TYPES[$type]), 'Loại nhiệm vụ không hợp lệ.', 'type');
            $this->ledger->ensure($type === $readiness['type'], 'Loại nhiệm vụ không phù hợp với phương án và trạng thái của đơn.', 'type');
            if ($type === 'return') {
                app(DeliveryDecisionService::class)->ensureReturnRecord($actor, $order);
            }
            $before = $this->ledger->snapshot($order);
            $task = $order->tasks()->create(['warehouse_id' => $order->warehouse_id, 'shipper_id' => $shipper->id, 'created_by' => $actor->id, 'type' => $type, 'reason' => $data['reason']]);
            $task->assignments()->create(['shipper_id' => $shipper->id, 'created_by' => $actor->id, 'event' => 'assigned', 'reason' => $data['reason']]);
            $order->assigned_shipper_id = $shipper->id;
            $this->ledger->record($actor, $order, 'task_assigned', $before, $data['reason'], ['delivery_task_id' => $task->id, 'shipper_id' => $shipper->id]);

            return $task;
        }, 3);
    }

    public function pickup(User $actor, DeliveryTask $task, array $data, string $evidence): void
    {
        $reference = $task->order;
        DB::transaction(function () use ($actor, $task, $data, $evidence, $reference) {
            $order = $this->ledger->lock($reference);
            $task = DeliveryTask::lockForUpdate()->findOrFail($task->id);
            abort_unless($this->policy->shipper($actor, $task), 403);
            $this->ledger->ensure($task->type === 'pickup' && $task->status === 'assigned' && $order->status === 'approved', 'Nhiệm vụ đã xử lý hoặc không phải lấy hàng.');
            $this->ledger->ensure(! $order->pendingChange()->exists(), 'Đang có đề nghị thay đổi; chờ điều phối xử lý trước khi lấy.');
            $before = $this->ledger->snapshot($order);
            $successful = (bool) $data['successful'];
            $task->pickupAttempt()->create(['created_by' => $actor->id, 'successful' => $successful, 'condition' => $data['condition'], 'evidence_path' => $evidence, 'reason' => $data['reason']]);
            if ($successful) {
                app(OrderRouting::class)->ensureCoverage($order->warehouse_id, $order->area_id);
                $this->ledger->ensureNoIncidentDispatchHold($order);
                for ($number = 1; $number <= $order->parcel_count; $number++) {
                    $item = $order->parcels()->create(['parcel_number' => $number, 'holder_type' => 'shipper', 'holder_user_id' => $actor->id, 'warehouse_id' => $order->warehouse_id, 'condition' => $data['condition']]);
                    if ($data['condition'] === 'damaged') {
                        Discrepancy::create(['warehouse_id' => $order->warehouse_id, 'order_id' => $order->id, 'warehouse_item_id' => $item->id, 'created_by' => $actor->id,
                            'type' => 'damaged', 'severity' => 'high', 'reason' => $data['reason'], 'evidence_path' => $evidence]);
                    }
                }
                $task->status = 'in_custody';
                $order->status = 'picked_up';
            } else {
                $task->status = 'failed';
                $task->finished_at = now();
                $order->assigned_shipper_id = null;
            }
            $task->save();
            $this->ledger->record($actor, $order, $successful ? 'pickup_confirmed' : 'pickup_failed', $before, $data['reason']);
        }, 3);
    }

    public function recall(User $actor, DeliveryTask $task, string $reason): void
    {
        $reference = $task->order;
        DB::transaction(function () use ($actor, $task, $reason, $reference) {
            $order = $this->ledger->lock($reference);
            $task = DeliveryTask::lockForUpdate()->findOrFail($task->id);
            abort_unless($this->policy->dispatch($actor, $task->warehouse_id), 403);
            $this->ledger->ensure(in_array($task->status, DeliveryTask::ACTIVE), 'Nhiệm vụ đã kết thúc.');
            $before = $this->ledger->snapshot($order);
            $hasCustody = $order->parcels()->where('holder_type', 'shipper')->where('holder_user_id', $task->shipper_id)->exists();
            $task->status = $hasCustody ? 'recall_pending' : 'cancelled';
            $task->finished_at = $hasCustody ? null : now();
            $task->save();
            // Hủy phiếu xuất chờ để không thể nhận kiện sau khi lệnh thu hồi có hiệu lực.
            $task->handovers()->where('status', 'pending')->where('direction', 'outbound')->update(['status' => 'cancelled']);
            $task->assignments()->create(['shipper_id' => $task->shipper_id, 'created_by' => $actor->id, 'event' => $task->status, 'reason' => $reason]);
            if (! $hasCustody) {
                $order->assigned_shipper_id = null;
            }
            $this->ledger->record($actor, $order, 'task_recalled', $before, $reason);
        }, 3);
    }

    public function authorizeReturn(User $actor, Order $order, string $reason): void
    {
        app(DeliveryDecisionService::class)->decide($actor, $order, ['decision' => 'return', 'reason' => $reason, 'shop_instruction' => $reason]);
    }
}
