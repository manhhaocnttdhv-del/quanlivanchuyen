@extends('layouts.portal')
{{-- Chứng từ điều chỉnh giữ khoản gốc; chỉ người khác có quyền được duyệt bản submitted và ghi sổ. --}}
@section('heading', 'Chi tiết điều chỉnh tài chính')
@section('content')
    @include('portal.finance.nav', ['section' => 'adjustments'])
    @include('portal.finance.identity')
    <dl class="detail-grid"><dt>Vận đơn</dt><dd class="order-code">{{ $document->order->tracking_code }}</dd><dt>Loại</dt><dd>{{ \App\Models\Adjustment::KINDS[$document->kind] }}</dd><dt>Số tiền căn cứ</dt><dd>{{ number_format((int) $document->amount, 0, ',', '.') }}đ</dd><dt>Căn cứ</dt><dd>{{ $document->incident?->code ?? $document->original?->code }}</dd><dt>Tác động lên khoản gốc</dt><dd>{{ number_format((int) $document->line->amount, 0, ',', '.') }}đ</dd><dt>Người duyệt</dt><dd>{{ $document->approver?->name ?? 'Chưa duyệt' }}</dd></dl>
    @if ($document->line->delivery_collection_id)<a href="{{ route('finance.collections.show', $document->line->collection) }}">Khoản thu #{{ $document->line->delivery_collection_id }}</a>@endif
    @if ($document->line->receivable_id)<a href="{{ route('finance.fees.show', $document->line->receivable) }}">Khoản phí #{{ $document->line->receivable_id }}</a>@endif
    @if ($document->reversal)<p class="mt-3 text-danger">Đã đảo bằng <a href="{{ route('finance.adjustments.show', $document->reversal) }}">{{ $document->reversal->code }}</a></p>@endif
    @include('portal.finance.attachments')
    @if ($document->status === 'submitted' && $policy->approve(auth()->user(), $document))
        <section class="work-section"><h2>Xét duyệt điều chỉnh</h2><div class="finance-action-grid">
            @include('portal.finance.action', ['routeName' => 'finance.adjustments.action', 'operation' => 'approve', 'label' => 'Duyệt và ghi sổ', 'icon' => 'shield-check'])
            @include('portal.finance.action', ['routeName' => 'finance.adjustments.action', 'operation' => 'reject', 'label' => 'Từ chối điều chỉnh', 'icon' => 'x'])
        </div></section>
    @endif
    @include('portal.finance.history')
@endsection
