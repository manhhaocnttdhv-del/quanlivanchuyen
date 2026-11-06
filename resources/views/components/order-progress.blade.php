@props(['order'])
@if ($order->status === 'lost')
    <div class="alert alert-danger" role="status"><i data-lucide="package-x" aria-hidden="true"></i> Đã xác nhận mất hàng. Vận đơn dừng vận chuyển; bồi thường được theo dõi riêng.</div>
@else
@php
    $steps = ['Tiếp nhận', 'Lấy hàng', 'Kho & vận chuyển', 'Giao / hoàn', 'Hoàn tất'];
    $current = match ($order->status) {
        'approved' => 1,
        'picked_up', 'at_warehouse', 'in_transit' => 2,
        'out_for_delivery', 'delivery_failed', 'returning', 'return_failed' => 3,
        'delivered', 'returned' => 4,
        default => 0,
    };
@endphp
<ol class="shipment-progress" aria-label="Tiến trình vận đơn">
    @foreach ($steps as $index => $step)
        <li class="{{ $index < $current ? 'is-complete' : ($index === $current ? 'is-current' : '') }}" @if ($index === $current) aria-current="step" @endif>
            <span class="shipment-step">@if ($index < $current)<i data-lucide="check-check"></i>@else{{ $index + 1 }}@endif</span><span>{{ $step }}</span>
        </li>
    @endforeach
</ol>
@endif
