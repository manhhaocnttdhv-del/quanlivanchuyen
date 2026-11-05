<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Warehouse;

class OrderRouting
{
    public function ready(Order $order, string $type): bool
    {
        if ($type === 'return') {
            return $order->origin_warehouse_id !== null && $order->warehouse_id === $order->origin_warehouse_id;
        }

        return Warehouse::whereKey($order->warehouse_id)->where('area_id', $order->area_id)->exists();
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
