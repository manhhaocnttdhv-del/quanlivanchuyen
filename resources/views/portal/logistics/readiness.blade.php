<div class="operations-next-step">
    <div><span class="small text-secondary">Bước hiện tại</span><strong>{{ $workflow['label'] }}</strong></div>
    @if ($workflow['pendingHandover'])
        <a class="btn btn-primary" href="{{ route('logistics.handover', $workflow['pendingHandover']) }}"><i data-lucide="package-check"></i> Mở phiếu bàn giao</a>
    @elseif ($workflow['activeTask'])
        <a class="btn btn-primary" href="{{ route('logistics.show', $workflow['activeTask']) }}"><i data-lucide="truck"></i> Mở nhiệm vụ</a>
    @elseif ($order->pendingChange || $order->status === 'submitted')
        @can('review', $order)<a class="btn btn-primary" href="{{ route('orders.show', $order) }}"><i data-lucide="clipboard-list"></i> Xét duyệt</a>@endcan
    @endif
</div>
@if (in_array($order->status, ['approved', 'at_warehouse']) && !$workflow['activeTask'] && !$workflow['canAssign'] && !$workflow['canTransfer'])
    <div class="alert alert-warning" role="status"><ul class="mb-0 ps-3">@foreach ($workflow['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
@endif
