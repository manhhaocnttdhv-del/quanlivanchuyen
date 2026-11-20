@extends('layouts.portal')
{{-- Chỉ điều phối đúng kho thấy form phân công/phương án; Service vẫn kiểm tra revision, khóa và lượt thử. --}}
@section('heading', 'Điều phối vận đơn')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('logistics.index') }}">
    <i data-lucide="arrow-left"></i> Điều phối</a>@endsection
@section('content')
    @include('portal.cases.order-links')
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $order->tracking_code }}</strong>
            <p>{{ $order->shop->name }} · {{ $order->warehouse->name }} · {{ $order->parcel_count }} kiện</p>
        </div><span class="status-badge">{{ \App\Models\Order::STATUSES[$order->status] }}</span>
    </div>
    <x-order-progress :order="$order" />
    @include('portal.logistics.readiness')
    <dl class="detail-grid">
        <dt>Lấy hàng</dt>
        <dd>{{ $order->sender_snapshot['address'] ?? '' }} · {{ $order->sender_snapshot['area'] ?? '' }}</dd>
        <dt>Giao hàng</dt>
        <dd>{{ $order->recipient_snapshot['address'] ?? '' }} · {{ $order->recipient_snapshot['area'] ?? '' }}</dd>
        <dt>Đã giao thử</dt>
        <dd>{{ $order->delivery_attempt_count }} / {{ $order->delivery_attempt_limit }} lượt @if ($order->retry_authorized_attempt)
                · Đã cho phép lượt {{ $order->retry_authorized_attempt }}
            @endif
        </dd>
    </dl>
    @include('portal.transfers.create')
    @if ($policy->dispatch(auth()->user(), $order->warehouse_id))
        @if (
            $order->tasks->whereIn('status', \App\Models\DeliveryTask::ACTIVE)->isEmpty() &&
                in_array($order->status, ['approved', 'at_warehouse']))
            @if ($workflow['canAssign'])
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
            @endif
            @if ($workflow['canDecide'])
                <section class="work-section">
                    <h2>{{ $order->delivery_attempt_count ? 'Phương án giao lại / hoàn' : 'Yêu cầu hoàn hàng' }}</h2>
                    <form method="post" action="{{ route('delivery.decide', $order) }}" class="narrow-form mt-3"
                        data-confirm="Lưu phương án xử lý theo ý kiến shop?">
                        @csrf<input type="hidden" name="revision" value="{{ $order->revision }}">
                        <div class="row g-3">
                            <div class="col-sm-6"><x-field name="decision" label="Phương án" :options="$decisions" required />
                            </div>
                            <div class="col-sm-6"><x-field name="fee_payment" label="Cách thu phí nếu hoàn"
                                    :options="\App\Models\ReturnRecord::PAYMENTS" required /></div>
                            <div class="col-12"><x-field name="shop_instruction" label="Ý kiến / yêu cầu của shop"
                                    type="textarea" rows="2" minlength="5" maxlength="1000" required /></div>
                            <div class="col-12"><x-field name="reason" id="decision-reason" label="Căn cứ quyết định"
                                    type="textarea" rows="2" minlength="5" maxlength="1000" required /></div>
                        </div>
                        <button class="btn btn-outline-primary mt-3"><i data-lucide="save"></i> Lưu phương án</button>
                    </form>
                </section>
            @endif
        @endif
    @endif
    @if ($transfers->isNotEmpty())
        <section class="work-section"><h2>Lịch sử chuyển kho</h2>
            @foreach ($transfers as $transfer)
                @if (app(\App\Policies\WarehouseTransferPolicy::class)->view(auth()->user(), $transfer))
                    <p class="mt-3"><a href="{{ route('transfers.show', $transfer) }}">{{ $transfer->code }}</a> · {{ $transfer->source->name }} → {{ $transfer->destination->name }} · {{ \App\Models\WarehouseTransfer::STATUSES[$transfer->status] }}</p>
                @endif
            @endforeach
        </section>
    @endif
    @if ($order->return_reason)
        <p class="alert alert-warning">Quyết định hoàn: {{ $order->return_reason }}</p>
    @endif
    @include('portal.delivery.attempts', ['attempts' => $order->deliveryAttempts])
    @if ($order->deliveryDecisions->isNotEmpty())
        <section class="work-section">
            <h2>Lịch sử phương án</h2>
            @foreach ($order->deliveryDecisions as $decision)
                <p class="small mt-3"><strong>{{ \App\Models\DeliveryDecision::TYPES[$decision->decision] }}</strong> ·
                    {{ $decision->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}<br>Shop:
                    {{ $decision->shop_instruction }}<br>Căn cứ: {{ $decision->reason }}</p>
            @endforeach
        </section>
    @endif
    <section class="work-section">
        <h2>Kiện và người giữ</h2>@include('portal.logistics.parcels')
    </section>
    <section class="work-section">
        <h2>Các nhiệm vụ</h2>
        @forelse ($order->tasks->sortByDesc('id') as $task)
            <p>@if ($policy->view(auth()->user(), $task))<a href="{{ route('logistics.show', $task) }}">@endif#{{ $task->id }} ·
                    {{ \App\Models\DeliveryTask::TYPES[$task->type] }} · {{ $task->shipper->name }}@if ($policy->view(auth()->user(), $task))</a>@endif <span
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
