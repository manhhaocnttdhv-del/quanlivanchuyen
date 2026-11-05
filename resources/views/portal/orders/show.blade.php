@extends('layouts.portal')
{{-- Nút theo Policy và trạng thái chỉ hỗ trợ UI; Service vẫn kiểm tra khóa/revision trước mỗi thay đổi. --}}
@section('heading', 'Chi tiết vận đơn')
@section('actions')
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary" href="{{ route('orders.index') }}"><i data-lucide="arrow-left"></i> Danh sách</a>
        @can('update', $order)@if (in_array($order->status, [...\App\Models\Order::EDITABLE, 'approved']) && !$order->pendingChange)
            <a class="btn btn-primary" href="{{ route('orders.edit', $order) }}"><i data-lucide="pencil"></i>
                {{ $order->status === 'approved' ? 'Đề nghị sửa' : 'Chỉnh sửa' }}</a>
        @endif @endcan
        @can('print', $order)@if (!$order->pendingChange)
            <a class="btn btn-outline-primary" href="{{ route('orders.label', $order) }}"><i data-lucide="printer"></i> In
                tem</a>
        @endif @endcan
    </div>
@endsection
@section('content')
    @include('portal.cases.order-links')
    <div class="order-identity">
        <div><span class="order-code">{{ $order->tracking_code }}</span>
            <p>{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }} ·
                {{ $order->warehouse?->name ?: 'Chưa tiếp nhận' }}</p>
        </div><span
            class="status-badge order-state state-{{ $order->status }}">{{ \App\Models\Order::STATUSES[$order->status] ?? $order->status }}</span>
    </div>
    <div class="row g-4">
        <div class="col-xl-8">
            <section class="work-section order-details">@include('portal.orders.details')</section>
        </div>
        <div class="col-xl-4">
            <aside class="order-charges">
                <h2>Cước và thu hộ</h2>
                <dl>
                    <div>
                        <dt>Tiền hàng COD</dt>
                        <dd>{{ number_format((int) $order->cod_amount, 0, ',', '.') }}đ</dd>
                    </div>
                    <div>
                        <dt>Phí vận chuyển</dt>
                        <dd>{{ $quote ? number_format($quote['total'], 0, ',', '.') . 'đ' : 'Chưa tính' }}</dd>
                    </div>
                    <div>
                        <dt>Người trả phí</dt>
                        <dd>{{ $order->fee_payer === 'sender' ? 'Người gửi' : 'Người nhận' }}</dd>
                    </div>
                    <div class="charge-total">
                        <dt>Cần thu người nhận</dt>
                        <dd>{{ $quote ? number_format($quote['recipient_total'], 0, ',', '.') . 'đ' : 'Chưa tính' }}</dd>
                    </div>
                </dl>
                @if ($quote)
                    <details class="small">
                        <summary>Chi tiết tính cước</summary>
                        <p class="mt-2">{{ $quote['version'] }} · {{ $quote['route'] }}</p>
                        <p>Cơ bản {{ number_format($quote['base_fee'], 0, ',', '.') }}đ /
                            {{ $quote['base_weight_grams'] }}g; thêm {{ $quote['steps'] }} bước ×
                            {{ number_format($quote['step_fee'], 0, ',', '.') }}đ.</p>
                    </details>
                @endif
                @if (in_array($order->status, \App\Models\Order::EDITABLE))
                    <p class="small text-secondary mt-3 mb-0">Cước xem trước. Phí được tính lại theo biểu phí hiệu lực khi
                        gửi duyệt.</p>
                @endif
            </aside>
        </div>
    </div>
    @can('update', $order)
        @if (in_array($order->status, \App\Models\Order::EDITABLE))
            <section class="work-section">
                <h2>Xác nhận gửi duyệt</h2>
                @if ($previewErrors)
                    <ul class="text-danger small mt-3">
                        @foreach ($previewErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
                @if ($order->shop->status !== 'approved')
                    <p class="text-warning-emphasis mt-3">Hồ sơ người gửi chưa được duyệt. Cập nhật hồ sơ và chờ xét duyệt trước khi
                        gửi.</p>
                @endif
                <form method="post" action="{{ route('orders.submit', $order) }}">@csrf<input type="hidden" name="revision"
                        value="{{ $order->revision }}"><button class="btn btn-primary mt-2" @disabled($previewErrors || $order->shop->status !== 'approved')><i
                            data-lucide="send"></i> Xác nhận gửi duyệt</button></form>
            </section>
        @endif
        @if (in_array($order->status, [...\App\Models\Order::EDITABLE, 'submitted', 'approved']) &&
                $order->custody_status === 'uncollected' &&
                !$order->pendingChange)
            <section class="work-section">
                <details>
                    <summary class="text-danger">{{ $order->status === 'approved' ? 'Đề nghị hủy vận đơn' : 'Hủy vận đơn' }}
                    </summary>
                    <form class="narrow-form mt-3" method="post"
                        action="{{ route($order->status === 'approved' ? 'orders.request-cancel' : 'orders.cancel', $order) }}"
                        data-confirm="{{ $order->status === 'approved' ? 'Gửi đề nghị hủy vận đơn này?' : 'Xác nhận hủy vận đơn này?' }}">
                        @csrf<input type="hidden" name="revision" value="{{ $order->revision }}"><x-field name="reason"
                            id="cancel-reason" label="Lý do hủy" minlength="5" maxlength="1000" required /><button
                            class="btn btn-outline-danger mt-3"><i data-lucide="x"></i>
                            {{ $order->status === 'approved' ? 'Gửi đề nghị hủy' : 'Xác nhận hủy' }}</button></form>
                </details>
            </section>
        @endif
    @endcan
    @can('review', $order)@if ($order->status === 'submitted')
        <section class="work-section">
            <h2>Xét duyệt vận đơn</h2>
            <form class="narrow-form mt-3" method="post" action="{{ route('orders.review', $order) }}">@csrf<input
                    type="hidden" name="revision" value="{{ $order->revision }}">
                <div class="row g-3">
                    <div class="col-md-5"><x-field name="decision" label="Kết quả" :options="['approved' => 'Duyệt đơn', 'needs_info' => 'Yêu cầu bổ sung', 'rejected' => 'Từ chối']" required /></div>
                    <div class="col-md-7"><x-field name="reason" id="review-reason" label="Lý do / căn cứ" minlength="5"
                            maxlength="1000" required /></div>
                </div><button class="btn btn-primary mt-3"><i data-lucide="circle-check"></i> Lưu kết quả</button>
            </form>
        </section>
    @endif @endcan
    {{-- Đề nghị sửa/hủy có lịch sử riêng; approved mới được áp vào dữ liệu gốc tại Service. --}}
    @if ($order->changeRequests->isNotEmpty())
        <section class="work-section">
            <h2>Đề nghị thay đổi</h2>
            @foreach ($order->changeRequests as $change)
                <article class="change-entry">
                    <div class="d-flex gap-2 flex-wrap justify-content-between">
                        <strong>{{ $change->type === 'cancel' ? 'Đề nghị hủy' : ($change->type === 'measured_weight' ? 'Đề nghị tính lại cước theo cân thực tế' : 'Đề nghị chỉnh sửa') }}</strong><span
                            class="status-badge">{{ ['pending' => 'Chờ xử lý', 'approved' => 'Đã chấp nhận', 'rejected' => 'Đã từ chối'][$change->status] }}</span>
                    </div>
                    <p class="small text-secondary mt-2">{{ $change->creator->name }} ·
                        {{ $change->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</p>
                    <p>{{ $change->reason }}</p>
                    @if ($change->changes)
                        <details class="small">
                            <summary>Thông tin đề nghị</summary>
                            <dl class="detail-grid mt-3">
                                @foreach (\App\Support\OrderRules::attributes() as $field => $label)
                                    @if (isset($change->changes[$field]) && is_scalar($change->changes[$field]))
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ $field === 'warehouse_id' ? $warehouseNames->get($change->changes[$field], $change->changes[$field]) : $change->changes[$field] }}</dd>
                                    @endif
                                @endforeach
                            </dl>
                            @foreach ($change->changes['items'] ?? [] as $item)
                                <p>{{ $item['name'] }} × {{ $item['quantity'] }}</p>
                            @endforeach
                        </details>
                    @endif
                    @if ($change->reviewed_at)
                        <p class="small mt-3 mb-0">{{ $change->reviewer?->name }}: {{ $change->review_reason }}</p>
                    @endif
                    @can('review', $order)
                        @if ($change->status === 'pending')
                            <form class="narrow-form mt-3" method="post"
                                action="{{ route('orders.change-review', $change) }}">@csrf<x-field name="decision"
                                    id="change-decision-{{ $change->id }}" label="Xử lý đề nghị" :options="['approved' => 'Chấp nhận đề nghị', 'rejected' => 'Từ chối đề nghị']" /><x-field
                                    name="reason" id="change-reason-{{ $change->id }}" label="Căn cứ xử lý" minlength="5"
                                    maxlength="1000" required /><button class="btn btn-primary mt-3">Lưu xử lý</button></form>
                        @endif
                    @endcan
                </article>
            @endforeach
        </section>
    @endif
    @if ($order->deliveryAttempts->isNotEmpty() || $order->returnRecord)
        @include('portal.delivery.attempts', ['attempts' => $order->deliveryAttempts])
    @endif
    <section class="work-section">
        <h2>Lịch sử vận đơn</h2>
        <ol class="order-timeline">
            @foreach ($order->histories as $history)
                <li>
                    <div>
                        <strong>{{ \App\Models\OrderStatusHistory::EVENTS[$history->event] ?? \App\Models\OrderStatusHistory::LOGISTICS_EVENTS[$history->event] ?? \App\Models\OrderStatusHistory::WAREHOUSE_EVENTS[$history->event] ?? $history->event }}</strong><span>{{ $history->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</span>
                    </div>
                    <p>{{ $history->actor?->name }} ·
                        {{ $history->from_status ? (\App\Models\Order::STATUSES[$history->from_status] ?? $history->from_status) . ' → ' : '' }}{{ \App\Models\Order::STATUSES[$history->to_status] ?? $history->to_status }}
                    </p>
                    <p>{{ $history->reason }}</p>
                    <details>
                        <summary>Thông tin ghi nhận</summary>
                        <div class="table-responsive">
                            <table class="table table-sm small">
                                <thead>
                                    <tr>
                                        <th>Trường</th>
                                        <th>Trước</th>
                                        <th>Sau</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['warehouse_id' => 'Kho tiếp nhận', 'cod_amount' => 'COD', 'shipping_fee' => 'Cước', 'fee_payer' => 'Người trả phí', 'weight_grams' => 'Cân nặng', 'recipient_name' => 'Người nhận', 'recipient_phone' => 'Điện thoại nhận', 'recipient_address' => 'Địa chỉ nhận', 'fee_version_id' => 'Phiên bản phí'] as $field => $label)
                                        @if (($history->before_values[$field] ?? null) != ($history->after_values[$field] ?? null))
                                            <tr>
                                                <td>{{ $label }}</td>
                                                <td>{{ $field === 'warehouse_id' ? $warehouseNames->get($history->before_values[$field] ?? null, $history->before_values[$field] ?? '—') : $history->before_values[$field] ?? '—' }}</td>
                                                <td>{{ $field === 'warehouse_id' ? $warehouseNames->get($history->after_values[$field] ?? null, $history->after_values[$field] ?? '—') : $history->after_values[$field] ?? '—' }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </li>
            @endforeach
        </ol>
    </section>
@endsection
