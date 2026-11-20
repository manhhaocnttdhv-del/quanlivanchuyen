<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Order;
use App\Models\User;
use App\Policies\LogisticsPolicy;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

class OrderQrService
{
    public function url(Order $order): string
    {
        return route('shipping.track-form', ['code' => $order->tracking_code]);
    }

    public function png(Order $order): string
    {
        // QR chỉ chứa URL và mã đơn; xác minh điện thoại vẫn thực hiện ở trang tra cứu.
        return (new Writer(new GDLibRenderer(400)))->writeString($this->url($order));
    }

    public function destination(User $actor, string $code): ?string
    {
        if ($actor->hasRole('shipper')) {
            $task = DeliveryTask::forShipper($actor)->whereIn('status', DeliveryTask::ACTIVE)
                ->whereHas('order', fn ($query) => $query->where('tracking_code', $code))->latest('id')->first();

            return $task && app(LogisticsPolicy::class)->view($actor, $task) ? route('logistics.show', $task) : null;
        }

        $order = Order::visibleTo($actor)->where('tracking_code', $code)->first();
        if (! $order || ! $actor->can('view', $order)) {
            return null;
        }

        return route('orders.show', $order);
    }
}
