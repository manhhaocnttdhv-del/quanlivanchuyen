@extends('layouts.portal')
{{-- Form lấy/giao/hoàn hoặc bàn giao phụ thuộc loại việc và người đang giữ; Policy kiểm lại ở server. --}}
@section('heading', \App\Models\DeliveryTask::TYPES[$task->type] . ' · Nhiệm vụ #' . $task->id)
@section('actions')<a class="btn btn-outline-primary"
        href="{{ route(auth()->user()->hasRole('shipper') ? 'delivery.mine' : 'logistics.index') }}">
    <i data-lucide="arrow-left"></i> Nhiệm vụ</a>@endsection
@section('content')
    @include('portal.cases.order-links')
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $order->tracking_code }}</strong>
            <p>{{ $task->shipper->name }} · {{ $task->warehouse->name }}</p>
        </div><span class="status-badge">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }}</span>
    </div>
    <x-order-progress :order="$order" />
    @php($pendingHandover = $task->handovers->where('status', 'pending')->sortByDesc('id')->first())
    @if ($pendingHandover)
        <div class="operations-next-step"><div><span class="small text-secondary">Bước tiếp theo</span><strong>{{ $pendingHandover->direction === 'inbound' ? 'Chờ kho kiểm đếm và xác nhận nhập' : 'Chờ shipper kiểm đếm và nhận kiện' }}</strong></div><a class="btn btn-primary" href="{{ route('logistics.handover', $pendingHandover) }}"><i data-lucide="package-check"></i> Mở phiếu bàn giao</a></div>
    @elseif ($task->status === 'assigned' && $task->type !== 'pickup' && $policy->shipper(auth()->user(), $task))
        <p class="alert alert-info">Chờ kho lập phiếu xuất giao kiện.</p>
    @endif
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <h2 class="h6">Lấy / hoàn về shop</h2>
            <p>{{ $order->sender_snapshot['name'] }} · <a
                    href="tel:{{ $order->sender_snapshot['phone'] }}">{{ $order->sender_snapshot['phone'] }}</a></p>
            <p>{{ $order->sender_snapshot['address'] }} · {{ $order->sender_snapshot['area'] }}</p>
        </div>
        <div class="col-md-6">
            <h2 class="h6">Người nhận</h2>
            <p>{{ $order->recipient_name }} · <a
                    href="tel:{{ $order->recipient_phone }}">{{ $order->recipient_phone }}</a></p>
            <p>{{ $order->recipient_address }} · {{ $order->recipient_snapshot['area'] }}</p>
        </div>
    </div>
    @if ($task->status === 'recall_pending')
        <p class="alert alert-warning">Nhiệm vụ đang thu hồi. Bàn giao toàn bộ kiện đang giữ về kho.</p>
    @endif
    @if (in_array($task->type, ['delivery', 'return']) &&
            $task->status === 'in_custody' &&
            !$pendingHandover &&
            $policy->shipper(auth()->user(), $task))
        @include('portal.delivery.form')
    @endif
    @if ($task->deliveryAttempt)
        @include('portal.delivery.attempts', ['attempts' => collect([$task->deliveryAttempt])])
    @endif
    @if ($task->type === 'pickup' && $task->status === 'assigned' && $policy->shipper(auth()->user(), $task))
        <section class="work-section">
            <h2>Kết quả lấy hàng</h2>
            <form class="narrow-form mt-3" method="post" enctype="multipart/form-data"
                action="{{ route('logistics.pickup', $task) }}">@csrf
                <div class="row g-3">
                    <div class="col-sm-6"><x-field name="successful" label="Kết quả" :options="[1 => 'Lấy đủ hàng', 0 => 'Không lấy được']" required /></div>
                    <div class="col-sm-6"><x-field name="condition" label="Tình trạng" :options="['good' => 'Nguyên vẹn', 'damaged' => 'Hư hỏng']" required /></div>
                    <div class="col-12"><x-field name="evidence" type="file" accept="image/jpeg,image/png,image/webp"
                            label="Ảnh bằng chứng" required /></div>
                    <div class="col-12"><x-field name="reason" label="Ghi nhận / lý do" minlength="5" maxlength="1000"
                            required /></div>
                </div>
                <button class="btn btn-primary mt-3"><i data-lucide="circle-check"></i> Xác nhận kết quả lấy</button>
            </form>
        </section>
    @endif
    @if ($task->pickupAttempt)
        <p class="small">Lượt lấy: {{ $task->pickupAttempt->successful ? 'Thành công' : 'Thất bại' }} ·
            {{ $task->pickupAttempt->reason }} · <a target="_blank"
                href="{{ route('logistics.evidence', ['kind' => 'pickup', 'id' => $task->pickupAttempt->id]) }}">Ảnh bằng
                chứng</a></p>
    @endif
    @if (in_array($task->status, ['in_custody', 'recall_pending']) && !$pendingHandover && $policy->shipper(auth()->user(), $task))
        <section class="work-section">
            <h2>Bàn giao về kho</h2>
            <form method="post" class="narrow-form mt-3" action="{{ route('logistics.prepare', $task) }}">@csrf<input
                    type="hidden" name="direction" value="inbound"><x-field name="reason" id="inbound-reason"
                    label="Lý do nhập / trả lại kho" minlength="5" maxlength="1000" required /><button
                    class="btn btn-primary mt-3"><i data-lucide="package-check"></i> Lập phiếu bàn giao về kho</button>
            </form>
        </section>
    @endif
    @if ($task->status === 'assigned' && $task->type !== 'pickup' && !$pendingHandover && $policy->warehouse(auth()->user(), $task->warehouse_id))
        <section class="work-section">
            <h2>Xuất giao cho shipper</h2>
            <form method="post" class="narrow-form mt-3" action="{{ route('logistics.prepare', $task) }}">@csrf<input
                    type="hidden" name="direction" value="outbound"><x-field name="reason" id="outbound-reason"
                    label="Ghi nhận xuất giao" minlength="5" maxlength="1000" required /><button
                    class="btn btn-primary mt-3"><i data-lucide="truck"></i> Lập phiếu xuất</button></form>
        </section>
    @endif
    <section class="work-section">
        <h2>Phiếu bàn giao</h2>
        @forelse ($task->handovers as $doc)
            <div class="handover-row"><a class="logistics-code"
                    href="{{ route('logistics.handover', $doc) }}">{{ $doc->code }}</a><span>{{ $doc->direction === 'inbound' ? 'Nhập kho' : 'Xuất kho' }}</span><span
                    class="status-badge">{{ ['pending' => 'Chờ xác nhận', 'confirmed' => 'Đã xác nhận', 'cancelled' => 'Đã hủy'][$doc->status] }}</span><small>{{ $doc->creator->name }}
                → {{ $doc->confirmer?->name ?? 'Chờ phía nhận' }}</small></div>@empty<p class="text-secondary mt-3">
                Chưa có phiếu bàn giao.</p>
        @endforelse
    </section>
    <section class="work-section">
        <h2>Kiện hiện tại</h2>@include('portal.logistics.parcels')
    </section>
    <section class="work-section">
        <h2>Lịch sử phân công</h2>
        @foreach ($task->assignments as $entry)
            <p class="small">{{ $entry->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }} ·
                {{ $entry->actor->name }} · {{ $entry->reason }}</p>
        @endforeach
    </section>
    @if (in_array($task->status, \App\Models\DeliveryTask::ACTIVE) && $policy->dispatch(auth()->user(), $task->warehouse_id))
        <section class="work-section">
            <details>
                <summary class="text-danger">Thu hồi nhiệm vụ</summary>
                <form class="narrow-form mt-3" method="post" action="{{ route('logistics.recall', $task) }}"
                    data-confirm="Thu hồi nhiệm vụ này?">@csrf<x-field name="reason" id="recall-reason"
                        label="Lý do thu hồi" minlength="5" maxlength="1000" required /><button
                        class="btn btn-outline-danger mt-3"><i data-lucide="rotate-ccw"></i> Thu hồi</button></form>
            </details>
        </section>
    @endif
@endsection
