@extends('layouts.portal')
@section('heading', 'Chi tiết phiếu thu phí')
@section('content')
    @include('portal.finance.nav', ['section' => 'receipts'])
    @include('portal.finance.identity')
    <dl class="detail-grid"><dt>Shop</dt><dd>{{ $document->shop->name }}</dd><dt>Mã giao dịch</dt><dd>{{ $document->transaction_reference }}</dd><dt>Thực nhận</dt><dd>{{ number_format((int) $document->amount, 0, ',', '.') }}đ</dd></dl>
    <section class="work-section"><h2>Phân bổ nợ phí</h2>
        @foreach ($document->allocations as $allocation)<div class="finance-source-row"><a href="{{ route('finance.fees.show', $allocation->receivable) }}">#{{ $allocation->receivable_id }} · {{ $allocation->receivable->order?->tracking_code ?? $allocation->receivable->reconciliation?->code }}</a><strong>{{ number_format((int) $allocation->amount, 0, ',', '.') }}đ</strong></div>@endforeach
        @include('portal.finance.attachments')
    </section>
    @include('portal.finance.history')
@endsection
