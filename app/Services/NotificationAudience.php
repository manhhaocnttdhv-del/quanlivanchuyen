<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Reconciliation;
use App\Models\User;
use App\Models\WarehouseTransfer;
use App\Support\NotificationEvents;

class NotificationAudience
{
    public function describe(AuditLog $log): ?array
    {
        if (! NotificationEvents::supports($log)) {
            return null;
        }
        [$category, $title] = NotificationEvents::EVENTS[$log->event];
        $target = match ($category) {
            'order' => $log->subject_type === Order::class ? Order::find($log->subject_id) : null,
            'task' => $log->subject_type === Order::class ? DeliveryTask::where('order_id', $log->subject_id)->find($log->new_values['delivery_task_id'] ?? 0) : null,
            'transfer' => $log->subject_type === Order::class ? WarehouseTransfer::where('order_id', $log->subject_id)->find($log->new_values['transfer_id'] ?? $log->new_values['transfer']['id'] ?? 0) : null,
            'delivery' => $log->subject_type === DeliveryAttempt::class ? DeliveryAttempt::find($log->subject_id)?->order : null,
            'incident' => $log->subject_type === Incident::class ? Incident::find($log->subject_id) : null,
            'finance' => $log->subject_type === Reconciliation::class ? Reconciliation::find($log->subject_id) : null,
        };
        if (! $target) {
            return null;
        }
        if ($log->event === 'order.reviewed') {
            $title = 'Vận đơn: '.(Order::STATUSES[$log->new_values['status'] ?? ''] ?? 'Đã xét duyệt');
        }
        if ($target instanceof DeliveryTask) {
            $title = 'Nhiệm vụ mới: '.DeliveryTask::TYPES[$target->type];
        }
        $type = array_search($target::class, NotificationTarget::MODELS, true);
        $reference = $target instanceof DeliveryTask ? $target->order->tracking_code : ($target->tracking_code ?? $target->code);

        return ['target' => $target, 'data' => ['category' => $category, 'event' => $log->event, 'title' => $title,
            'reference' => $reference, 'target_type' => $type, 'target_id' => $target->id]];
    }

    public function candidates(AuditLog $log, $target)
    {
        $query = User::where('status', 'active')->with('roles');
        if ($target instanceof WarehouseTransfer) {
            $warehouseIds = $log->event === 'logistics.transfer_dispatched' ? [$target->destination_warehouse_id] : [$target->source_warehouse_id, $target->destination_warehouse_id];

            return $query->whereHas('roles', fn ($q) => $q->whereHas('permissions', fn ($p) => $p->whereIn('name', ['logistics.warehouse', 'logistics.dispatch'])))
                ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'admin'))->orWhereHas('warehouses', fn ($w) => $w->whereIn('warehouses.id', $warehouseIds)))
                ->when($log->actor_id, fn ($q, $id) => $q->where('users.id', '!=', $id));
        }
        $ids = match ($log->event) {
            'order.reviewed' => [$target->shop->user_id],
            'logistics.task_assigned' => [$target->shipper_id],
            'finance.reconciliation_approve' => [$target->shop->user_id, $target->created_by],
            'finance.reconciliation_return', 'finance.reconciliation_reject' => [$target->created_by],
            default => [],
        };
        if ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $permission = match ($log->event) {
                'order.submitted', 'delivery.attempt_recorded' => ['orders.review'],
                'incident.created' => ['incidents.manage', 'incidents.approve'],
                default => ['finance.approve'],
            };
            $query->where(function ($q) use ($target, $permission, $log) {
                $q->where(function ($staff) use ($target, $permission) {
                    $staff->whereHas('roles', fn ($r) => $r->whereHas('permissions', fn ($p) => $p->whereIn('name', $permission)))
                        ->where(fn ($scope) => $scope->whereHas('roles', fn ($r) => $r->where('name', 'admin'))
                            ->orWhereHas('warehouses', fn ($w) => $w->whereKey($target->warehouse_id)));
                });
                if (in_array($log->event, ['delivery.attempt_recorded', 'finance.reconciliation_submit'])) {
                    // Chu shop nhan thong bao chung; khong nhan ly do/bang chung noi bo.
                    $q->orWhere('users.id', $target->shop->user_id);
                }
            });
        }
        $query->when($log->actor_id, fn ($q, $id) => $q->where('users.id', '!=', $id));

        return $query;
    }
}
