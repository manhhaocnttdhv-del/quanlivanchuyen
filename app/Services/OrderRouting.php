<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Order;
use App\Models\Warehouse;

class OrderRouting
{
    public function warehouseActive(Order $order): bool
    {
        return Warehouse::whereKey($order->warehouse_id)->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true))->exists();
    }

    public function ensureWarehouseActive(int $warehouseId, string $field = 'reason'): void
    {
        $warehouse = Warehouse::whereKey($warehouseId)->sharedLock()->first();
        $area = $warehouse ? Area::whereKey($warehouse->area_id)->sharedLock()->first() : null;
        app(LogisticsLedger::class)->ensure($warehouse?->is_active && $area?->is_active, 'Kho hoặc khu vực đã ngừng xuất hàng. Thu hồi nhiệm vụ hoặc chờ mở lại kho.', $field);
    }

    public function ensureCoverage(int $warehouseId, int $areaId): void
    {
        $this->ensureWarehouseActive($warehouseId, 'warehouse_id');
        $active = Warehouse::where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true));
        $ledger = app(LogisticsLedger::class);
        $ledger->ensure((clone $active)->whereKey($warehouseId)->exists(), 'Kho tiếp nhận đang ngừng phục vụ. Chọn kho khác trước khi gửi duyệt.', 'warehouse_id');
        $ledger->ensure((clone $active)->where('area_id', $areaId)->exists(), 'Khu vực người nhận chưa có kho giao đang hoạt động. Chọn khu vực được phục vụ.', 'area_id');
    }

    public function ready(Order $order, string $type): bool
    {
        if ($type === 'return') {
            return $order->origin_warehouse_id !== null && $order->warehouse_id === $order->origin_warehouse_id;
        }

        return $order->area_id !== null && $order->warehouse?->area_id === $order->area_id;
    }

    public function ensureReady(Order $order, string $type): void
    {
        app(LogisticsLedger::class)->ensure($this->ready($order, $type), $type === 'return'
            ? 'Phải chuyển đủ kiện về kho gửi ban đầu trước khi phân công hoàn cho shop.'
            : 'Phải chuyển đủ kiện đến kho thuộc khu vực người nhận trước khi phân công giao.');
    }

    public function destinations(Order $order)
    {
        return Warehouse::where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true))
            ->whereKeyNot($order->warehouse_id)
            ->when($order->return_authorized_by, fn ($q) => $q->whereKey($order->origin_warehouse_id), fn ($q) => $q->where('area_id', $order->area_id));
    }
}
