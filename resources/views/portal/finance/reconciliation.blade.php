@extends('layouts.portal')
{{-- Nguồn và ngân hàng là snapshot; Policy tách người lập/duyệt, chỉ thanh toán succeeded mới giảm phải trả. --}}
@section('heading', 'Chi tiết đối soát shop')
@section('content')
    @include('portal.finance.nav', ['section' => 'reconciliations'])
    @include('portal.finance.identity')
    <dl class="detail-grid"><dt>Shop / kỳ</dt><dd>{{ $document->shop->name }} · {{ $document->period_start->format('d/m/Y') }} đến {{ $document->period_end->format('d/m/Y') }}</dd><dt>Ngân hàng đã chốt</dt><dd>{{ $document->bank_snapshot['bank_name'] }} · {{ $document->bank_snapshot['holder_name'] }} · {{ $document->bank_snapshot['account_number'] }}</dd><dt>Người duyệt</dt><dd>{{ $document->approver?->name ?? 'Chưa duyệt' }}</dd></dl>
    <dl class="delivery-totals">
        @foreach (['cod_total' => 'COD thu hộ', 'fee_total' => 'Phí khấu trừ', 'adjustment_total' => 'Bồi thường', 'payable_amount' => 'Phải trả shop', 'debt_amount' => 'Shop còn nợ phí'] as $field => $label)
            <div><dt>{{ $label }}</dt><dd>{{ number_format((int) $document->$field, 0, ',', '.') }}đ</dd></div>
        @endforeach
    </dl>
    <section class="work-section"><h2>Nguồn đối soát · phiên bản {{ $document->line_version }}</h2>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Vận đơn</th><th>Nguồn</th><th class="text-end">Số tiền</th></tr></thead><tbody>
            @foreach ($document->lines as $line)<tr><td class="order-code">{{ $line->order->tracking_code }}</td><td>{{ $line->source_label }}</td><td class="text-end">{{ $line->type === 'fee' ? '−' : '+' }}{{ number_format((int) $line->amount, 0, ',', '.') }}đ</td></tr>@endforeach
        </tbody></table></div>
    </section>
    @if ($policy->staff(auth()->user(), $document->warehouse_id))
        @if (in_array($document->status, ['draft', 'returned']))
            <section class="work-section"><h2>Hoàn thiện đối soát</h2>
                @if (! $document->approved_at)<a class="btn btn-outline-primary" href="{{ route('finance.reconciliations.edit', $document) }}"><i data-lucide="pencil"></i> Cập nhật nguồn / ngân hàng</a>
                @else
                    @include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'refresh-bank', 'label' => 'Cập nhật ngân hàng đã xác minh', 'icon' => 'rotate-ccw'])
                @endif
                <div class="finance-action-grid">
                    @include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'submit', 'label' => 'Gửi duyệt', 'icon' => 'send'])
                    @if (! $document->approved_at)@include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'cancel', 'label' => 'Hủy bản nháp', 'icon' => 'x'])@endif
                </div>
            </section>
        @endif
        @if ($document->status === 'submitted' && $policy->approve(auth()->user(), $document))
            <section class="work-section"><h2>Xét duyệt đối soát</h2><div class="finance-action-grid">
                @include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'approve', 'label' => 'Duyệt đối soát', 'icon' => 'shield-check'])
                @include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'return', 'label' => 'Trả lại bổ sung', 'icon' => 'rotate-ccw'])
                @if (! $document->approved_at)@include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'reject', 'label' => 'Từ chối đối soát', 'icon' => 'x'])@endif
            </div></section>
        @endif
        @if ($document->status === 'approved' && $policy->approve(auth()->user(), $document))
            <details class="work-section"><summary>Duyệt lại khi thay đổi ngân hàng</summary>
                @include('portal.finance.action', ['routeName' => 'finance.reconciliations.action', 'operation' => 'reopen', 'label' => 'Mở quy trình duyệt lại ngân hàng', 'icon' => 'rotate-ccw'])
            </details>
        @endif
    @endif
    {{-- Ledger chỉ cộng phân bổ thanh toán thành công; giao dịch failed không giảm nghĩa vụ trả shop. --}}
    @php($paid = $ledger->paid($document->id))
    <section class="work-section"><h2>Thanh toán shop</h2>
        <dl class="delivery-totals"><div><dt>Đã thanh toán thành công</dt><dd>{{ number_format($paid, 0, ',', '.') }}đ</dd></div><div><dt>Còn phải trả</dt><dd>{{ number_format((int) $document->payable_amount - $paid, 0, ',', '.') }}đ</dd></div></dl>
        @foreach ($payments as $payment)<div class="case-entry"><div class="finance-source-row"><strong class="logistics-code">{{ $payment->code }}</strong><span class="status-badge">{{ \App\Models\Payment::STATUSES[$payment->status] }}</span><strong>{{ number_format((int) $payment->amount, 0, ',', '.') }}đ</strong></div><p class="small mt-2 mb-0">{{ $payment->transaction_reference }} · {{ $payment->paid_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</p><p class="small">{{ $payment->reason }}</p>@include('portal.finance.attachments', ['document' => $payment])</div>@endforeach
        @if ($document->status === 'approved' && $policy->staff(auth()->user(), $document->warehouse_id) && (int) $document->payable_amount > $paid)
            <form method="post" action="{{ route('finance.payments.store', $document) }}" enctype="multipart/form-data" class="finance-form mt-4" data-confirm="Ghi nhận giao dịch trả shop theo chứng từ ngân hàng?">
                @csrf<x-client-token />
                <div class="row g-3"><div class="col-md-6"><x-field name="amount" type="number" label="Số tiền giao dịch (đ)" min="1" :max="(int) $document->payable_amount - $paid" step="1" required /></div><div class="col-md-6"><x-field name="status" label="Kết quả ngân hàng" :options="\App\Models\Payment::STATUSES" required /></div></div>
                <x-field name="transaction_reference" label="Mã giao dịch ngân hàng" minlength="4" maxlength="100" required />
                <x-field name="reason" id="payment-reason" label="Nội dung thanh toán" type="textarea" rows="3" minlength="5" maxlength="2000" required />
                <x-field name="evidence" label="Chứng từ giao dịch" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
                <button class="btn btn-primary mt-3"><i data-lucide="banknote"></i> Ghi nhận thanh toán</button>
            </form>
        @endif
    </section>
    @include('portal.finance.history')
@endsection
