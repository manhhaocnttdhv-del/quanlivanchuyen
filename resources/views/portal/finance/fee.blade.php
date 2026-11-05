@extends('layouts.portal')
@section('heading', 'Chi tiết công nợ phí')
@section('content')
    @include('portal.finance.nav', ['section' => 'fees'])
    <div class="case-identity"><strong class="order-code">{{ $source->order?->tracking_code ?? $source->reconciliation?->code }}</strong><span>#{{ $source->id }} · {{ \App\Models\Receivable::TYPES[$source->type] }}</span></div>
    <dl class="delivery-totals"><div><dt>Phí gốc</dt><dd>{{ number_format((int) $source->amount, 0, ',', '.') }}đ</dd></div><div><dt>Điều chỉnh</dt><dd>{{ number_format((int) $source->correction_amount, 0, ',', '.') }}đ</dd></div><div><dt>Đã thu</dt><dd>{{ number_format((int) $source->received_amount, 0, ',', '.') }}đ</dd></div><div><dt>Đã giữ đối soát</dt><dd>{{ number_format((int) $source->reserved_amount, 0, ',', '.') }}đ</dd></div><div><dt>Còn nợ khả dụng</dt><dd>{{ number_format($queries->available($source), 0, ',', '.') }}đ</dd></div></dl>
    <section class="work-section"><h2>Phiếu thu nợ phí</h2>
        @forelse ($source->receiptAllocations as $allocation)<div class="finance-source-row"><a href="{{ route('finance.receipts.show', $allocation->receipt) }}">{{ $allocation->receipt->code }}</a><strong>{{ number_format((int) $allocation->amount, 0, ',', '.') }}đ</strong></div>@empty<p class="text-secondary small">Chưa có phiếu thu trực tiếp.</p>@endforelse
    </section>
    @include('portal.finance.source-adjustments')
    @include('portal.finance.source-reconciliations')
@endsection
