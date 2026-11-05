@extends('layouts.portal')
@section('heading', 'Chi tiết khoản thu')
@section('content')
    @include('portal.finance.nav', ['section' => 'collections'])
    <div class="case-identity"><strong class="order-code">{{ $source->order->tracking_code }}</strong><span>#{{ $source->id }} · {{ \App\Models\DeliveryCollection::TYPES[$source->type] }}</span></div>
    <dl class="detail-grid mt-4"><dt>Shipper thu</dt><dd>{{ $source->shipper->name }}</dd><dt>Thời gian thu</dt><dd>{{ $source->collected_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</dd><dt>Dự kiến / gốc thực thu</dt><dd>{{ number_format((int) $source->expected_amount, 0, ',', '.') }}đ / {{ number_format((int) $source->amount, 0, ',', '.') }}đ</dd><dt>Điều chỉnh đã duyệt</dt><dd>{{ number_format((int) $source->correction_amount, 0, ',', '.') }}đ</dd></dl>
    <dl class="delivery-totals"><div><dt>Thực thu hiệu lực</dt><dd>{{ number_format($queries->effective($source), 0, ',', '.') }}đ</dd></div><div><dt>Đã nộp quỹ</dt><dd>{{ number_format((int) $source->deposited_amount, 0, ',', '.') }}đ</dd></div><div><dt>Shipper còn giữ</dt><dd>{{ number_format($queries->held($source), 0, ',', '.') }}đ</dd></div><div><dt>Đã giữ đối soát</dt><dd>{{ number_format((int) $source->reserved_amount, 0, ',', '.') }}đ</dd></div></dl>
    <section class="work-section"><h2>Tiền đã về quỹ</h2>
        @forelse ($source->depositAllocations as $allocation)<div class="finance-source-row"><span>@if ($policy->view(auth()->user(), $allocation->deposit))<a href="{{ route('finance.deposits.show', $allocation->deposit) }}">{{ $allocation->deposit->code }}</a>@else{{ $allocation->deposit->code }}@endif</span><time>{{ $allocation->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time><strong>{{ number_format((int) $allocation->amount, 0, ',', '.') }}đ</strong></div>@empty<p class="text-secondary small">Chưa phân bổ tiền về quỹ.</p>@endforelse
    </section>
    @include('portal.finance.source-adjustments')
    @include('portal.finance.source-reconciliations')
@endsection
