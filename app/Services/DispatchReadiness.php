<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class DispatchReadiness
{
    public function query(Builder $query): Builder
    {
        return $query->with(['shop', 'warehouse.area', 'pendingChange',
            'tasks' => fn ($q) => $q->whereIn('status', DeliveryTask::ACTIVE)->with(['shipper', 'handovers' => fn ($q) => $q->where('status', 'pending')]),
        ])->withCount($this->counts());
    }

    public function inspect(Order $order): array
    {
        if (! array_key_exists('active_tasks_count', $order->getAttributes())) {
            $order->loadCount($this->counts());
        }
        $order->loadMissing(['warehouse.area', 'pendingChange', 'tasks.handovers']);
        $common = [];
        if (! $order->warehouse?->is_active || ! $order->warehouse?->area?->is_active) {
            $common[] = 'Kho hoặc khu vực phụ trách đang ngừng hoạt động.';
        }
        if ($order->pendingChange) {
            $common[] = 'Đang có đề nghị thay đổi chờ xét duyệt.';
        }
        if ($order->dispatch_incidents_count > 0) {
            $common[] = 'Đang khóa vận chuyển do sự cố hoặc kiện đã xác nhận mất.';
        }
        if ($order->dispatch_discrepancies_count > 0) {
            $common[] = 'Đang giữ kiện do sai lệch chưa được xác minh.';
        }
        if (! $order->return_authorized_by && $order->served_destination_count === 0) {
            $common[] = 'Khu vực người nhận chưa có kho giao đang hoạt động.';
        }
        if ($order->return_authorized_by && $order->served_origin_count === 0) {
            $common[] = 'Kho gửi ban đầu đang ngừng hoạt động; cần xử lý nơi tiếp nhận hàng hoàn.';
        }
        if ($order->active_tasks_count > 0) {
            $common[] = 'Đã có nhiệm vụ đang thực hiện.';
        }
        $fullStock = $order->custody_status === 'warehouse' && $order->parcels_count === $order->parcel_count
            && $order->warehouse_parcels_count === $order->parcel_count;
        $warehouseBlockers = $common;
        if ($order->status !== 'at_warehouse' || ! $fullStock) {
            $warehouseBlockers[] = 'Chưa giữ đủ toàn bộ kiện thực tế tại kho phụ trách.';
        }
        $type = $order->status === 'approved' ? 'pickup' : ($order->return_authorized_by ? 'return' : 'delivery');
        $routeReady = app(OrderRouting::class)->ready($order, $type === 'pickup' ? 'delivery' : $type);
        $blockers = $type === 'pickup' ? $common : $warehouseBlockers;
        if ($type === 'pickup') {
            if ($order->custody_status !== 'uncollected' || $order->parcels_count > 0) {
                $blockers[] = 'Vận đơn đã có kiện, không thể phân công lấy lại.';
            }
        } else {
            if (! $routeReady) {
                $blockers[] = $type === 'return' ? 'Cần chuyển đủ kiện về kho gửi ban đầu để hoàn.' : 'Cần chuyển đủ kiện đến kho thuộc khu vực người nhận.';
            }
            if ($type === 'delivery') {
                if ($order->delivery_attempt_count >= $order->delivery_attempt_limit) {
                    $blockers[] = 'Đã hết lượt giao; cần phương án ngoại lệ hoặc hoàn hàng.';
                } elseif ($order->delivery_attempt_count > 0 && $order->retry_authorized_attempt !== $order->delivery_attempt_count + 1) {
                    $blockers[] = 'Cần quyết định giao lại theo ý kiến người gửi.';
                }
            }
        }
        $activeTask = $order->tasks->whereIn('status', DeliveryTask::ACTIVE)->sortByDesc('id')->first();
        $pendingHandover = $activeTask?->handovers->where('status', 'pending')->sortByDesc('id')->first();
        $canAssign = in_array($order->status, ['approved', 'at_warehouse']) && $blockers === [];
        $canTransfer = $warehouseBlockers === [] && ! $routeReady;
        $canDecide = $order->status === 'at_warehouse' && $fullStock && ! $order->active_tasks_count && ! $order->pendingChange && ! $order->return_authorized_by;
        $label = Order::STATUSES[$order->status];
        $action = 'Mở xử lý';
        if ($pendingHandover) {
            $label = $pendingHandover->direction === 'inbound' ? 'Kho xác nhận nhập kiện' : 'Shipper xác nhận nhận kiện';
            $action = 'Kiểm đếm bàn giao';
        } elseif ($activeTask) {
            $label = $activeTask->status === 'recall_pending' ? 'Chờ shipper trả kiện về kho' : DeliveryTask::TYPES[$activeTask->type].' · '.DeliveryTask::STATUSES[$activeTask->status];
            $action = 'Mở nhiệm vụ';
        } elseif ($order->pendingChange) {
            $label = 'Chờ xét đề nghị thay đổi';
            $action = 'Xét đề nghị';
        } elseif ($order->status === 'submitted') {
            $label = 'Chờ xét duyệt vận đơn';
            $action = 'Xét duyệt';
        } elseif (in_array($order->status, ['approved', 'at_warehouse'])) {
            $label = $canAssign ? ['pickup' => 'Chờ phân công lấy hàng', 'delivery' => 'Chờ phân công giao hàng', 'return' => 'Chờ phân công hoàn hàng'][$type]
                : ($canTransfer ? 'Chờ xuất chuyển kho' : ($blockers[0] ?? $label));
            $action = $canAssign ? 'Phân công' : ($canTransfer ? 'Xuất chuyển kho' : 'Mở xử lý');
        }

        return compact('type', 'blockers', 'warehouseBlockers', 'routeReady', 'canAssign', 'canTransfer', 'canDecide', 'label', 'action', 'activeTask', 'pendingHandover');
    }

    private function counts(): array
    {
        return ['tasks as active_tasks_count' => fn ($q) => $q->whereIn('status', DeliveryTask::ACTIVE), 'parcels',
            'parcels as warehouse_parcels_count' => fn ($q) => $q->where('holder_type', 'warehouse')->whereColumn('warehouse_items.warehouse_id', 'orders.warehouse_id'),
            'discrepancies as dispatch_discrepancies_count' => fn ($q) => $q->where('status', 'open')->where('blocks_dispatch', true),
            'incidents as dispatch_incidents_count' => fn ($q) => $q->blockingDispatch(),
            'area as served_destination_count' => fn ($q) => $q->where('is_active', true)->whereHas('warehouses', fn ($q) => $q->where('is_active', true)),
            'originWarehouse as served_origin_count' => fn ($q) => $q->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true))];
    }
}
