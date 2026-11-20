<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\User;

class ShipperMapService
{
    public function page(User $shipper, array $filters): array
    {
        $base = DeliveryTask::forShipper($shipper)->whereIn('status', DeliveryTask::ACTIVE);
        $counts = (clone $base)->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');
        $query = (clone $base)->with(['order', 'warehouse.area'])
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['q'] ?? null, fn ($q, $code) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $code.'%')));
        $tasks = (clone $query)->orderByRaw("status = 'recall_pending' DESC")->oldest('id')->paginate(12)->withQueryString();
        // Không dùng ID trên URL để bỏ qua phạm vi, trạng thái hoặc bộ lọc hiện tại.
        $selected = isset($filters['task']) ? (clone $query)->findOrFail($filters['task']) : $tasks->first();
        $stops = $tasks->getCollection()->mapWithKeys(fn ($task) => [$task->id => $this->stop($task)]);
        $stop = $selected ? $this->stop($selected) : null;

        return compact('tasks', 'counts', 'selected', 'stops', 'stop');
    }

    private function stop(DeliveryTask $task): array
    {
        $order = $task->order;
        $toWarehouse = $task->status === 'recall_pending'
            || ($task->type === 'pickup' && $task->status === 'in_custody')
            || ($task->type !== 'pickup' && $task->status === 'assigned');
        if ($toWarehouse) {
            $phase = $task->status === 'assigned' ? 'Đến kho nhận kiện' : 'Trả kiện về kho';
            $name = $task->warehouse->name;
            $address = $task->warehouse->address;
            $area = $task->warehouse->area?->name;
            $phone = null;
        } elseif ($task->type === 'delivery') {
            $phase = 'Giao người nhận';
            $name = $order->recipient_name;
            $address = $order->recipient_address;
            $area = $order->recipient_snapshot['area'] ?? null;
            $phone = $order->recipient_phone;
        } else {
            $phase = $task->type === 'pickup' ? 'Lấy tại người gửi' : 'Hoàn người gửi';
            $name = $order->sender_snapshot['name'] ?? null;
            $address = $order->sender_snapshot['address'] ?? null;
            $area = $order->sender_snapshot['area'] ?? null;
            $phone = $order->sender_snapshot['phone'] ?? null;
        }
        $address = trim((string) $address);
        $destination = implode(', ', array_filter([$address, $area, 'Việt Nam']));
        // Chỉ gửi địa chỉ điểm đến cho bản đồ; không đưa tên, điện thoại hay mã đơn vào URL.
        $embed = $address === '' ? null : 'https://maps.google.com/maps?'.http_build_query(['q' => $destination, 'output' => 'embed', 'hl' => 'vi'], '', '&', PHP_QUERY_RFC3986);
        $directions = $address === '' ? null : 'https://www.google.com/maps/dir/?'.http_build_query(['api' => 1, 'destination' => $destination, 'travelmode' => 'driving'], '', '&', PHP_QUERY_RFC3986);

        return compact('phase', 'name', 'address', 'area', 'phone', 'embed', 'directions');
    }
}
