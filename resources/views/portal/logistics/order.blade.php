@extends('layouts.portal')
@section('heading', 'Điều phối vận đơn')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('logistics.index') }}">
    <i data-lucide="arrow-left"></i> Điều phối</a>@endsection
@section('content')
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $order->tracking_code }}</strong>
            <p>{{ $order->shop->name }} · {{ $order->warehouse->name }} · {{ $order->parcel_count }} kiện</p>
        </div><span class="status-badge">{{ \App\Models\Order::STATUSES[$order->status] }}</span>
    </div>
    <dl class="detail-grid">
        <dt>Lấy hàng</dt>
        <dd>{{ $order->sender_snapshot['address'] ?? '' }} · {{ $order->sender_snapshot['area'] ?? '' }}</dd>
        <dt>Giao hàng</dt>
        <dd>{{ $order->recipient_snapshot['address'] ?? '' }} · {{ $order->recipient_snapshot['area'] ?? '' }}</dd>
        <dt>Đã giao thử</dt>
        <dd>{{ $order->delivery_attempt_count }} / 3 lượt</dd>
    </dl>
    @if ($policy->dispatch(auth()->user(), $order->warehouse_id))
        @if (
            $order->tasks->whereIn('status', \App\Models\DeliveryTask::ACTIVE)->isEmpty() &&
                in_array($order->status, ['approved', 'at_warehouse']))
            <section class="work-section">
                <h2>Phân công nhiệm vụ</h2>
                <form method="post" action="{{ route('logistics.assign', $order) }}" class="narrow-form mt-3">@csrf
                    <input type="hidden" name="revision" value="{{ $order->revision }}">
                    <div class="row g-3">
                        <div class="col-sm-5"><x-field name="type" label="Nhiệm vụ" :options="$order->status === 'approved' ? ['pickup' => 'Lấy hàng'] : ($order->return_authorized_by ? ['return' => 'Hoàn hàng'] : ['delivery' => 'Giao hàng'])" required /></div>
                        <div class="col-sm-7"><x-field name="shipper_id" label="Shipper / tải hiện tại" :options="$shippers"
                                placeholder="Chọn shipper" required /></div>
                        <div class="col-12"><x-field name="reason" label="Tuyến / căn cứ phân công" minlength="5"
                                maxlength="1000" required /></div>
                    </div>
                    <button class="btn btn-primary mt-3" @disabled($shippers->isEmpty())><i data-lucide="truck"></i> Phân
                        công</button>
                </form>
            </section>
            @if ($order->status === 'at_warehouse' && !$order->return_authorized_by)
                <section class="work-section">
                    <details>
                        <summary>Quyết định hoàn hàng</summary>
                        <form method="post" action="{{ route('logistics.return', $order) }}" class="narrow-form mt-3"
                            data-confirm="Xác nhận chuyển đơn sang phương án hoàn?">@csrf<x-field name="reason"
                                id="return-reason" label="Căn cứ / ý kiến shop" minlength="5" maxlength="1000"
                                required /><button class="btn btn-outline-danger mt-3"><i data-lucide="rotate-ccw"></i> Lưu
                                quyết định hoàn</button></form>
                    </details>
                </section>
            @endif
        @endif
    @endif
    @if ($order->return_reason)
        <p class="alert alert-warning">Quyết định hoàn: {{ $order->return_reason }}</p>
    @endif
    <section class="work-section">
        <h2>Kiện và người giữ</h2>@include('portal.logistics.parcels')
    </section>
    <section class="work-section">
        <h2>Các nhiệm vụ</h2>
        @forelse ($order->tasks->sortByDesc('id') as $task)
            <p><a href="{{ route('logistics.show', $task) }}">#{{ $task->id }} ·
                    {{ \App\Models\DeliveryTask::TYPES[$task->type] }} · {{ $task->shipper->name }}</a> <span
                class="status-badge">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }}</span></p>@empty<p
                class="text-secondary">Chưa phân công.</p>
        @endforelse
    </section>
    <section class="work-section">
        <h2>Sai lệch và giữ kiện</h2>
        @if ($policy->dispatch(auth()->user(), $order->warehouse_id))
            <a href="{{ route('orders.show', $order) }}">Mở lịch sử và duyệt đề nghị cước</a>
        @endif @include('portal.logistics.discrepancies', [
            'discrepancies' => $order->discrepancies->sortByDesc('id'),
        ])
    </section>
@endsection
