<?php

namespace App\Services;

use App\Models\DeliveryTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OperationsWorklist
{
    public const QUEUES = ['all' => 'Tất cả', 'review' => 'Chờ duyệt', 'pickup' => 'Lấy hàng', 'warehouse' => 'Bàn giao',
        'transfer' => 'Chuyển đi', 'receipt' => 'Kho nhận', 'delivery' => 'Giao hàng', 'decision' => 'Giao lại / hoàn',
        'return' => 'Hoàn hàng', 'held' => 'Đang giữ', 'transit' => 'Đang vận chuyển'];

    public function filter(Builder $query, string $queue): Builder
    {
        if ($queue === 'all') {
            return $query;
        }
        if ($queue === 'receipt') {
            return $query->whereKey([]);
        }
        if ($queue === 'review') {
            return $query->where(fn ($q) => $q->where('status', 'submitted')->orWhereHas('pendingChange'));
        }
        if ($queue === 'held') {
            return $query->where(fn ($q) => $q->whereHas('incidents', fn ($q) => $q->blockingDispatch())
                ->orWhereHas('discrepancies', fn ($q) => $q->where('status', 'open')->where('blocks_dispatch', true))
                ->orWhereDoesntHave('warehouse', fn ($q) => $q->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true)))
                ->orWhere(fn ($q) => $q->whereNull('return_authorized_by')->whereDoesntHave('area', fn ($q) => $q->where('is_active', true)->whereHas('warehouses', fn ($q) => $q->where('is_active', true))))
                ->orWhere(fn ($q) => $q->whereNotNull('return_authorized_by')->whereDoesntHave('originWarehouse', fn ($q) => $q->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true)))));
        }
        if ($queue === 'transit') {
            return $query->whereIn('status', ['in_transit', 'out_for_delivery', 'returning']);
        }
        if ($queue === 'warehouse') {
            return $query->whereHas('tasks', fn ($q) => $q->where('status', 'recall_pending')
                ->orWhere(fn ($q) => $q->where('type', 'pickup')->where('status', 'in_custody'))
                ->orWhere(fn ($q) => $q->where('type', '!=', 'pickup')->where('status', 'assigned')));
        }
        $query->whereDoesntHave('tasks', fn ($q) => $q->whereIn('status', DeliveryTask::ACTIVE))
            ->whereDoesntHave('pendingChange')->whereDoesntHave('incidents', fn ($q) => $q->blockingDispatch())
            ->whereDoesntHave('discrepancies', fn ($q) => $q->where('status', 'open')->where('blocks_dispatch', true))
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true)));
        $query->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('return_authorized_by')->whereHas('originWarehouse', fn ($q) => $q->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true))))
            ->orWhere(fn ($q) => $q->whereNull('return_authorized_by')->whereHas('area', fn ($q) => $q->where('is_active', true)->whereHas('warehouses', fn ($q) => $q->where('is_active', true)))));
        if ($queue === 'pickup') {
            return $query->where('status', 'approved')->where('custody_status', 'uncollected')->whereDoesntHave('parcels');
        }
        $query->where('status', 'at_warehouse')->where('custody_status', 'warehouse')
            ->whereHas('parcels', fn ($q) => $q->where('holder_type', 'warehouse')->whereColumn('warehouse_items.warehouse_id', 'orders.warehouse_id'), '=', DB::raw('orders.parcel_count'));
        if ($queue === 'decision') {
            return $query->whereNull('return_authorized_by')->where('delivery_attempt_count', '>', 0)->whereNull('retry_authorized_attempt');
        }
        if ($queue === 'transfer') {
            return $query->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('return_authorized_by')->whereColumn('warehouse_id', '!=', 'origin_warehouse_id'))
                ->orWhere(fn ($q) => $q->whereNull('return_authorized_by')->whereHas('warehouse', fn ($q) => $q->whereColumn('warehouses.area_id', '!=', 'orders.area_id'))));
        }
        if ($queue === 'return') {
            return $query->whereNotNull('return_authorized_by')->whereColumn('warehouse_id', 'origin_warehouse_id');
        }

        return $query->whereNull('return_authorized_by')->whereHas('warehouse', fn ($q) => $q->whereColumn('warehouses.area_id', 'orders.area_id'))
            ->whereColumn('delivery_attempt_count', '<', 'delivery_attempt_limit')
            ->where(fn ($q) => $q->where('delivery_attempt_count', 0)->orWhere('retry_authorized_attempt', DB::raw('delivery_attempt_count + 1')));
    }
}
