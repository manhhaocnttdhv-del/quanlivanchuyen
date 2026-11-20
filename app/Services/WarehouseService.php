<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\HandoverDocument;
use App\Models\OrderChangeRequest;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Policies\LogisticsPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehouseService
{
    public function __construct(private LogisticsLedger $ledger, private LogisticsPolicy $policy, private AuditService $audit) {}

    public function prepare(User $actor, DeliveryTask $task, string $direction, string $reason): HandoverDocument
    {
        $reference = $task->order;

        return DB::transaction(function () use ($actor, $task, $direction, $reason, $reference) {
            $order = $this->ledger->lock($reference);
            $task = DeliveryTask::lockForUpdate()->findOrFail($task->id);
            $this->ledger->ensure(in_array($direction, ['inbound', 'outbound']), 'Hướng bàn giao không hợp lệ.', 'direction');
            $inbound = $direction === 'inbound';
            abort_unless($inbound ? $this->policy->shipper($actor, $task) : $this->policy->warehouse($actor, $task->warehouse_id), 403);
            $this->ledger->ensure(in_array($task->status, $inbound ? ['in_custody', 'recall_pending'] : ['assigned']), 'Nhiệm vụ không đủ điều kiện bàn giao.');
            $this->ledger->ensure(! $task->handovers()->where('status', 'pending')->exists(), 'Đã có phiếu bàn giao chờ xác nhận.');
            $this->ledger->ensure($order->warehouse_id === $task->warehouse_id, 'Kho phụ trách đã thay đổi; cần kiểm tra lại nhiệm vụ.');
            if (! $inbound) {
                app(OrderRouting::class)->ensureWarehouseActive($task->warehouse_id);
                app(OrderRouting::class)->ensureReady($order, $task->type);
                $this->ledger->ensure(in_array($task->type, ['delivery', 'return']) && $order->status === 'at_warehouse', 'Chỉ xuất giao/hoàn khi đơn tại kho.');
                $this->unblocked($order);
            }
            $items = $order->parcels()->where('holder_type', $inbound ? 'shipper' : 'warehouse')
                ->where('warehouse_id', $task->warehouse_id)
                ->when($inbound, fn ($q) => $q->where('holder_user_id', $task->shipper_id))->orderBy('id')->get();
            $this->ledger->ensure($items->isNotEmpty() && ($inbound || $items->count() === $order->parcel_count), 'Không đủ kiện hợp lệ để bàn giao.');
            $before = $this->ledger->snapshot($order);
            $doc = HandoverDocument::create(['code' => 'BG'.Str::upper((string) Str::ulid()), 'delivery_task_id' => $task->id, 'order_id' => $order->id,
                'warehouse_id' => $task->warehouse_id, 'shipper_id' => $task->shipper_id, 'created_by' => $actor->id, 'direction' => $direction, 'reason' => $reason]);
            foreach ($items as $item) {
                $doc->lines()->create(['warehouse_item_id' => $item->id, 'from_holder' => $inbound ? 'shipper:'.$task->shipper_id : 'warehouse:'.$task->warehouse_id]);
            }
            $this->ledger->record($actor, $order, 'handover_prepared', $before, $doc->code.': '.$reason);

            return $doc;
        }, 3);
    }

    public function confirm(User $actor, HandoverDocument $doc, array $data, ?string $evidence): void
    {
        $reference = $doc->order;
        DB::transaction(function () use ($actor, $doc, $data, $evidence, $reference) {
            $order = $this->ledger->lock($reference);
            $task = DeliveryTask::lockForUpdate()->findOrFail($doc->delivery_task_id);
            $doc = HandoverDocument::lockForUpdate()->findOrFail($doc->id);
            $inbound = $doc->direction === 'inbound';
            abort_unless($inbound ? $this->policy->warehouse($actor, $doc->warehouse_id) : $this->policy->shipper($actor, $task), 403);
            $this->ledger->ensure($doc->created_by !== $actor->id, 'Hai phía bàn giao phải là hai tài khoản khác nhau.');
            $this->ledger->ensure($doc->status === 'pending' && in_array($task->status, $inbound ? ['in_custody', 'recall_pending'] : ['assigned']), 'Phiếu đã xử lý hoặc nhiệm vụ không còn phù hợp.');
            $this->ledger->ensure($order->warehouse_id === $doc->warehouse_id && $task->warehouse_id === $doc->warehouse_id, 'Kho phụ trách không còn khớp phiếu bàn giao.');
            if (! $inbound) {
                app(OrderRouting::class)->ensureWarehouseActive($doc->warehouse_id);
                app(OrderRouting::class)->ensureReady($order, $task->type);
                $this->unblocked($order);
            }
            $lines = $doc->lines()->with('item')->orderBy('id')->get();
            $provided = collect($data['lines']);
            $this->ledger->ensure($provided->count() === $lines->count() && $provided->keys()->map(fn ($key) => (int) $key)->sort()->values()->all() === $lines->pluck('id')->sort()->values()->all(), 'Phải kiểm đếm toàn bộ và đúng các dòng của phiếu.', 'lines');
            $location = null;
            if ($inbound) {
                $location = WarehouseLocation::whereKey($data['location_id'] ?? null)->where('warehouse_id', $doc->warehouse_id)->where('is_active', true)->first();
                $this->ledger->ensure((bool) $location, 'Chọn vị trí đang hoạt động trong đúng kho.', 'location_id');
            }
            $before = $this->ledger->snapshot($order);
            $receivedCount = 0;
            foreach ($lines as $line) {
                $input = $data['lines'][$line->id];
                $received = (bool) $input['received'];
                $item = $line->item;
                $this->ledger->ensure($item->warehouse_id === $doc->warehouse_id && $item->holder_type === ($inbound ? 'shipper' : 'warehouse') && (! $inbound || $item->holder_user_id === $task->shipper_id), 'Kho hoặc người giữ kiện đã thay đổi.');
                $damaged = $received && $input['condition'] === 'damaged';
                $this->ledger->ensure(($received && ! $damaged) || $evidence !== null, 'Thiếu/hỏng kiện cần ảnh bằng chứng.', 'evidence');
                if (! $received || $damaged) {
                    $this->discrepancy($actor, $doc, $item->id, ! $received ? 'missing' : 'damaged', $data['reason'], $evidence);
                }
                $line->received = $received;
                $line->condition = $received ? $input['condition'] : null;
                if ($received) {
                    $receivedCount++;
                    if ($inbound) {
                        $grams = (int) ($input['weight_grams'] ?? 0);
                        $this->ledger->ensure($grams >= 1 && $grams <= 20000000, 'Cân từng kiện thực nhận từ 1 đến 20.000.000 gram.', 'lines.'.$line->id.'.weight_grams');
                        $item->actual_weight_grams = $grams;
                        $item->actual_weight_kg = intdiv($grams, 1000).'.'.str_pad((string) ($grams % 1000), 3, '0', STR_PAD_LEFT);
                        $item->warehouse_location_id = $location->id;
                        $line->actual_weight_grams = $grams;
                    } else {
                        $item->warehouse_location_id = null;
                    }
                    $item->holder_type = $inbound ? 'warehouse' : 'shipper';
                    $item->holder_user_id = $inbound ? null : $task->shipper_id;
                    $item->condition = $input['condition'];
                    $item->custody_version++;
                    $item->save();
                    $line->to_holder = $inbound ? 'warehouse:'.$doc->warehouse_id : 'shipper:'.$task->shipper_id;
                }
                $line->save();
            }
            $doc->update(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'confirmation_reason' => $data['reason'], 'evidence_path' => $evidence]);
            $remaining = $order->parcels()->where('holder_type', 'shipper')->where('holder_user_id', $task->shipper_id)->count();
            if ($inbound && $remaining === 0) {
                $task->status = 'completed';
                $task->finished_at = now();
                $order->status = 'at_warehouse';
                $order->assigned_shipper_id = null;
                $total = (int) $order->parcels()->sum('actual_weight_grams');
                if ($total !== $order->weight_grams && ! $order->parcels()->where('holder_type', 'lost')->exists() && ! $order->discrepancies()->where('type', 'weight')->where('status', 'open')->exists()) {
                    $this->ledger->ensure($evidence !== null, 'Lệch cân cần ảnh cân làm bằng chứng.', 'evidence');
                    // Không sửa snapshot cước khi cân thực tế khác khai báo.
                    $this->discrepancy($actor, $doc, $lines->first()->warehouse_item_id, 'weight', 'Khai báo '.$order->weight_grams.'g; cân thực tế '.$total.'g. '.$data['reason'], $evidence);
                }
            } elseif (! $inbound) {
                $task->status = $receivedCount === $lines->count() ? 'in_custody' : ($receivedCount > 0 ? 'recall_pending' : 'failed');
                if ($receivedCount === 0) {
                    $task->finished_at = now();
                    $order->assigned_shipper_id = null;
                } else {
                    $order->status = $task->type === 'return' ? 'returning' : 'out_for_delivery';
                }
            }
            $task->save();
            $this->ledger->record($actor, $order, 'handover_confirmed', $before, $doc->code.': '.$data['reason']);
        }, 3);
    }

    public function resolve(User $actor, Discrepancy $discrepancy, string $reason): void
    {
        $reference = $discrepancy->order;
        DB::transaction(function () use ($actor, $discrepancy, $reason, $reference) {
            $order = $reference ? $this->ledger->lock($reference) : null;
            $discrepancy = Discrepancy::lockForUpdate()->findOrFail($discrepancy->id);
            abort_unless($this->policy->approve($actor, $discrepancy->warehouse_id), 403);
            $this->ledger->ensure($discrepancy->created_by !== $actor->id && $discrepancy->status === 'open', 'Người lập không tự bỏ giữ; sai lệch phải đang mở.');
            $this->ledger->ensure(! $order || ! $order->pendingChange()->where('type', 'measured_weight')->exists(), 'Xét duyệt đề nghị cân thực tế trước khi bỏ giữ.');
            $before = $order ? $this->ledger->snapshot($order) : [];
            $discrepancy->update(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolution_reason' => $reason, 'resolved_at' => now()]);
            $this->audit->record($actor, $discrepancy, 'discrepancy.resolved', ['status' => 'open'], ['status' => 'resolved', 'resolved_by' => $actor->id], $reason);
            if ($order) {
                $this->ledger->record($actor, $order, 'hold_released', $before, 'Sai lệch #'.$discrepancy->id.': '.$reason);
            }
        }, 3);
    }

    public function requestWeightReview(User $actor, Discrepancy $discrepancy, string $reason): void
    {
        $reference = $discrepancy->order;
        DB::transaction(function () use ($actor, $discrepancy, $reason, $reference) {
            $order = $this->ledger->lock($reference);
            abort_unless($this->policy->warehouse($actor, $order->warehouse_id), 403);
            $discrepancy = Discrepancy::lockForUpdate()->findOrFail($discrepancy->id);
            $this->ledger->ensure($discrepancy->type === 'weight' && $discrepancy->status === 'open' && $order->status === 'at_warehouse', 'Chỉ đề nghị cân lại khi sai lệch cân đang mở và đơn tại kho.');
            $this->ledger->ensure(! $order->pendingChange()->exists() && ! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists(), 'Đơn đang có nhiệm vụ hoặc đề nghị chờ xử lý.');
            $before = $this->ledger->snapshot($order);
            $grams = (int) $order->parcels()->sum('actual_weight_grams');
            $this->ledger->ensure($grams > 0 && $order->custody_status === 'warehouse', 'Phải nhận và cân đủ kiện trong kho.');
            $this->ledger->record($actor, $order, 'weight_review_requested', $before, $reason);
            OrderChangeRequest::create(['order_id' => $order->id, 'created_by' => $actor->id, 'type' => 'measured_weight', 'order_revision' => $order->revision,
                'changes' => ['weight_grams' => $grams, 'discrepancy_id' => $discrepancy->id], 'before_values' => $order->only(['weight_grams', 'shipping_fee', 'fee_snapshot', 'recipient_total']), 'reason' => $reason]);
        }, 3);
    }

    private function unblocked($order): void
    {
        $this->ledger->ensureNoIncidentDispatchHold($order);
        $this->ledger->ensure(! $order->pendingChange()->exists() && ! $order->discrepancies()->where('status', 'open')->where('blocks_dispatch', true)->exists(), 'Đơn có đề nghị thay đổi hoặc kiện đang giữ; chưa được xuất.');
    }

    private function discrepancy(User $actor, HandoverDocument $doc, int $itemId, string $type, string $reason, ?string $evidence): void
    {
        Discrepancy::create(['warehouse_id' => $doc->warehouse_id, 'order_id' => $doc->order_id, 'warehouse_item_id' => $itemId, 'handover_document_id' => $doc->id,
            'created_by' => $actor->id, 'type' => $type, 'severity' => $type === 'weight' ? 'medium' : 'high', 'reason' => $reason, 'evidence_path' => $evidence]);
    }
}
