{{-- Quyền báo sự cố và gửi khiếu nại kiểm riêng theo đơn; không suy ra từ việc xem được vận đơn. --}}
<div class="case-links">
    @if (app(\App\Policies\IncidentPolicy::class)->createForOrder(auth()->user(), $order))
        <a class="btn btn-outline-danger" href="{{ route('incidents.create', ['order_id' => $order->id]) }}"><i
                data-lucide="triangle-alert"></i> Báo sự cố</a>
        <a class="btn btn-outline-primary" href="{{ route('incidents.index', ['order_id' => $order->id]) }}">Hồ sơ sự cố
            của đơn</a>
    @endif
    @if (app(\App\Policies\ComplaintPolicy::class)->createForOrder(auth()->user(), $order))
        <a class="btn btn-outline-primary" href="{{ route('complaints.create', ['order_id' => $order->id]) }}"><i
                data-lucide="messages-square"></i> Gửi khiếu nại</a>
        <a class="btn btn-outline-primary" href="{{ route('complaints.index', ['order_id' => $order->id]) }}">Khiếu nại
            của đơn</a>
    @endif
</div>
