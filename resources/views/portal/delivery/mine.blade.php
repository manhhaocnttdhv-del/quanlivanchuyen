@extends('layouts.portal')
@section('heading', 'Nhiệm vụ của tôi')
@section('actions')
    <a class="btn btn-outline-primary" href="{{ route('delivery.collections') }}">
        <i data-lucide="banknote"></i> Sổ thu của tôi</a>
@endsection
@section('content')
    <div class="d-flex gap-3 flex-wrap align-items-center justify-content-between mb-4">
        <nav class="nav nav-underline" aria-label="Tình trạng nhiệm vụ">
            @foreach (['active' => 'Đang thực hiện', 'history' => 'Lịch sử'] as $value => $label)
                <a class="nav-link {{ ($active ? 'active' : 'history') === $value ? 'active' : '' }}"
                    href="{{ route('delivery.mine', array_merge($filters, ['view' => $value, 'page' => 1])) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <form method="get" action="{{ route('delivery.mine') }}" class="d-flex gap-2 delivery-search">
            <input type="hidden" name="type" value="{{ $filters['type'] ?? 'delivery' }}">
            <input type="hidden" name="view" value="{{ $active ? 'active' : 'history' }}">
            <input class="form-control" name="q" aria-label="Mã vận đơn" placeholder="Tìm mã vận đơn"
                value="{{ $filters['q'] ?? '' }}" maxlength="40">
            <button class="btn btn-outline-primary icon-button" aria-label="Tìm nhiệm vụ" title="Tìm nhiệm vụ"><i
                    data-lucide="search"></i></button>
        </form>
    </div>
    <nav class="delivery-tabs" aria-label="Loại nhiệm vụ">
        @foreach (\App\Models\DeliveryTask::TYPES as $value => $label)
            <a class="{{ ($filters['type'] ?? 'delivery') === $value ? 'active' : '' }}"
                @if (($filters['type'] ?? 'delivery') === $value) aria-current="page" @endif
                href="{{ route('delivery.mine', array_merge($filters, ['type' => $value, 'page' => 1])) }}">
                <i
                    data-lucide="{{ ['pickup' => 'package-check', 'delivery' => 'truck', 'return' => 'rotate-ccw'][$value] }}"></i><span>{{ $label }}</span><strong>{{ $counts[$value] ?? 0 }}</strong>
            </a>
        @endforeach
    </nav>
    <div class="delivery-list">
        @forelse ($tasks as $task)
            @php
                $order = $task->order;
                $sender = $task->type !== 'delivery';
                $name = $sender ? $order->sender_snapshot['name'] : $order->recipient_name;
                $phone = $sender ? $order->sender_snapshot['phone'] : $order->recipient_phone;
                $address = $sender ? $order->sender_snapshot['address'] : $order->recipient_address;
                $attempt = $task->deliveryAttempt;
                $cod = $task->type === 'delivery' ? (int) $order->cod_amount : 0;
                $fee =
                    $task->type === 'return'
                        ? ($order->returnRecord?->fee_payment === 'collect_on_return'
                            ? (int) $order->returnRecord->fee_amount
                            : 0)
                        : ($task->type === 'delivery' && $order->fee_payer === 'recipient'
                            ? (int) $order->shipping_fee
                            : 0);
                if ($attempt?->outcome === 'success') {
                    $cod = (int) $attempt->actual_cod;
                    $fee = (int) $attempt->actual_fee;
                }
            @endphp
            <article class="delivery-item">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><a class="logistics-code fw-semibold"
                        href="{{ route('logistics.show', $task) }}">{{ $order->tracking_code }}</a><span
                        class="status-badge">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }}</span></div>
                <h2 class="h6 mb-2">{{ $name }}</h2>
                <p class="delivery-address"><i data-lucide="map-pin"></i><span>{{ $address }}</span></p>
                <p class="small text-secondary">{{ $order->parcel_count }} kiện · {{ $task->warehouse->name }}
                    @if ($task->type === 'delivery')
                        · Lượt {{ $task->deliveryAttempt?->attempt_number ?? $order->delivery_attempt_count + 1 }}
                    @endif
                </p>
                @if ($attempt?->outcome === 'failed')
                    <p class="alert alert-warning small">Đã ghi thất bại, không phát sinh khoản thu.
                        {{ $task->status === 'recall_pending' ? 'Cần trả kiện về kho.' : 'Đã kết thúc lượt giao.' }}</p>
                @elseif ($task->type !== 'pickup')
                    <dl class="delivery-money">
                        <div>
                            <dt>{{ $attempt ? 'COD thực thu' : 'COD dự kiến' }}</dt>
                            <dd>{{ number_format($cod, 0, ',', '.') }}đ</dd>
                        </div>
                        <div>
                            <dt>{{ $attempt ? 'Phí thực thu' : 'Phí thu tại chỗ' }}</dt>
                            <dd>{{ number_format($fee, 0, ',', '.') }}đ</dd>
                        </div>
                        <div>
                            <dt>{{ $attempt ? 'Tổng đã thu' : 'Tổng cần thu' }}</dt>
                            <dd>{{ number_format($cod + $fee, 0, ',', '.') }}đ</dd>
                        </div>
                    </dl>
                @endif
                <div class="delivery-item-actions"><a class="btn btn-outline-primary" href="tel:{{ $phone }}"><i
                            data-lucide="phone"></i> {{ $phone }}</a><a class="btn btn-primary"
                        href="{{ route('logistics.show', $task) }}"><i data-lucide="arrow-right"></i> Mở nhiệm vụ</a></div>
            </article>
        @empty
            <div class="empty-state py-5"><i data-lucide="package-check"></i>
                <h2 class="h5 mt-3">Chưa có nhiệm vụ phù hợp</h2>
                <p class="text-secondary">
                    {{ $active ? 'Các nhiệm vụ mới sẽ xuất hiện khi điều phối phân công.' : 'Các nhiệm vụ đã kết thúc sẽ được lưu tại đây.' }}
                </p>
            </div>
        @endforelse
    </div>
    <div class="mt-4">{{ $tasks->links() }}</div>
@endsection
