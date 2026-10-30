{{-- Đơn đã gửi ưu tiên snapshot người gửi/nhận; thay danh mục sau này không làm thay địa chỉ chứng từ cũ. --}}
@php
    $sender = $order->sender_snapshot ?? [
        'name' => $order->pickupAddress?->contact_name,
        'phone' => $order->pickupAddress?->phone,
        'address' => $order->pickupAddress?->address,
        'area' => $order->pickupAddress?->area?->name,
    ];
    $recipient = $order->recipient_snapshot ?? [
        'name' => $order->recipient_name,
        'phone' => $order->recipient_phone,
        'address' => $order->recipient_address,
        'area' => $order->area?->name,
    ];
@endphp
<div class="row g-4">
    <div class="col-lg-6">
        <h2><i data-lucide="map-pin-house"></i> Lấy hàng</h2>
        <p class="mb-1 fw-semibold">{{ $order->shop->name }}</p>
        <p class="mb-1">{{ $sender['name'] ?: 'Chưa nhập' }} · {{ $sender['phone'] }}</p>
        <p class="text-secondary">{{ $sender['address'] }}{{ $sender['area'] ? ', ' . $sender['area'] : '' }}</p>
    </div>
    <div class="col-lg-6">
        <h2><i data-lucide="map-pin"></i> Giao hàng</h2>
        <p class="mb-1 fw-semibold">{{ $recipient['name'] ?: 'Chưa nhập' }}</p>
        <p class="mb-1">{{ $recipient['phone'] }}</p>
        <p class="text-secondary">{{ $recipient['address'] }}{{ $recipient['area'] ? ', ' . $recipient['area'] : '' }}</p>
    </div>
</div>
<div class="order-goods">
    <div><span>Hàng hóa</span>
        @forelse($order->items as $item)
        <p>{{ $item->name }} <small>× {{ $item->quantity }}</small></p>@empty<p>Chưa nhập</p>
        @endforelse
    </div>
    <div><span>Kiện hàng</span>
        <p>{{ $order->parcel_count }} kiện · {{ number_format($order->weight_grams ?? 0) }} g</p>
    </div>
    <div><span>Dịch vụ</span>
        <p>{{ config('shipping.services.' . $order->service . '.label') }}</p>
    </div>
</div>
@if ($order->note)
    <p class="order-note"><i data-lucide="info"></i> {{ $order->note }}</p>
@endif
