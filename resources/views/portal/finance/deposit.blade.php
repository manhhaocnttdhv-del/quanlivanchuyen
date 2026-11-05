@extends('layouts.portal')
@section('heading', 'Chi tiết phiếu nộp tiền')
@section('content')
    @include('portal.finance.nav', ['section' => 'deposits'])
    @include('portal.finance.identity')
    <dl class="detail-grid"><dt>Shipper</dt><dd>{{ $document->shipper->name }}</dd><dt>Người nhận</dt><dd>{{ $document->confirmer?->name ?? 'Chờ xác nhận' }}</dd><dt>Mã giao dịch</dt><dd>{{ $document->transaction_reference ?? 'Chưa ghi nhận' }}</dd></dl>
    <dl class="delivery-totals"><div><dt>Đề nghị nộp</dt><dd>{{ number_format((int) $document->requested_amount, 0, ',', '.') }}đ</dd></div><div><dt>Thực nhận</dt><dd>{{ number_format((int) $document->received_amount, 0, ',', '.') }}đ</dd></div><div><dt>Chưa phân bổ</dt><dd>{{ number_format($ledger->unallocated($document), 0, ',', '.') }}đ</dd></div></dl>
    <section class="work-section"><h2>Khoản thu đề nghị</h2>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nguồn</th><th>Khoản thu</th><th class="text-end">Đề nghị</th></tr></thead><tbody>
            @foreach ($document->lines as $line)<tr><td><a class="order-code" href="{{ route('finance.collections.show', $line->collection) }}">{{ $line->collection->order->tracking_code }} · #{{ $line->delivery_collection_id }}</a></td><td>{{ \App\Models\DeliveryCollection::TYPES[$line->collection->type] }}</td><td class="text-end">{{ number_format((int) $line->amount, 0, ',', '.') }}đ</td></tr>@endforeach
        </tbody></table></div>
    </section>
    @if ($document->status === 'submitted' && $policy->approve(auth()->user(), $document))
        <section class="work-section"><h2>Xác nhận tiền thực nhận</h2>
            <form method="post" action="{{ route('finance.deposits.action', [$document, 'confirm']) }}" enctype="multipart/form-data" class="finance-form" data-confirm="Xác nhận đã nhận tiền và ghi vào quỹ?">
                @csrf<input type="hidden" name="revision" value="{{ $document->revision }}">
                <div class="row g-3"><div class="col-md-6"><x-field name="received_amount" label="Tiền thực nhận (đ)" type="number" min="0" max="9999999999999" step="1" required /></div><div class="col-md-6"><x-field name="transaction_reference" label="Mã biên nhận / giao dịch" minlength="4" maxlength="100" required /></div></div>
                <div class="table-responsive mt-3"><table class="table align-middle"><thead><tr><th>Nguồn</th><th class="text-end">Phân bổ thực nhận (đ)</th></tr></thead><tbody>
                    @foreach ($document->lines as $line)<tr><td>#{{ $line->delivery_collection_id }} · {{ \App\Models\DeliveryCollection::TYPES[$line->collection->type] }}</td><td><x-finance-amount group="allocations" :source="$line->delivery_collection_id" :label="'Phân bổ khoản #'.$line->delivery_collection_id" :max="$ledger->held($line->collection)" /></td></tr>@endforeach
                </tbody></table></div>
                <x-field name="reason" id="confirm-reason" label="Kết quả kiểm đếm / lý do chênh lệch" type="textarea" rows="3" minlength="5" maxlength="2000" required />
                <x-field name="evidence" label="Biên nhận / chứng từ" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
                <button class="btn btn-primary mt-3"><i data-lucide="shield-check"></i> Xác nhận thực nhận</button>
            </form>
            @include('portal.finance.action', ['routeName' => 'finance.deposits.action', 'operation' => 'reject', 'label' => 'Từ chối đề nghị', 'icon' => 'x'])
        </section>
    @endif
    @if ($document->status === 'confirmed')
        <section class="work-section"><h2>Phân bổ đã ghi sổ</h2>
            @foreach ($document->allocations as $allocation)<div class="finance-source-row"><a href="{{ route('finance.collections.show', $allocation->collection) }}">{{ $allocation->collection->order->tracking_code }} · #{{ $allocation->delivery_collection_id }}</a><span>{{ $allocation->creator->name }}</span><strong>{{ number_format((int) $allocation->amount, 0, ',', '.') }}đ</strong></div>@endforeach
            @include('portal.finance.attachments')
        </section>
        @if ($ledger->unallocated($document) > 0 && $policy->staff(auth()->user(), $document->warehouse_id) && auth()->id() !== $document->shipper_id && auth()->id() !== $document->created_by)
            <section class="work-section"><h2>Phân bổ tiền còn dư</h2>
                <form method="post" action="{{ route('finance.deposits.action', [$document, 'allocate']) }}" class="finance-form">
                    @csrf<input type="hidden" name="revision" value="{{ $document->revision }}">
                    @foreach ($remaining as $source)<div class="finance-source-row"><span>#{{ $source->id }} · {{ $source->order->tracking_code }} · {{ \App\Models\DeliveryCollection::TYPES[$source->type] }}</span><x-finance-amount group="allocations" :source="$source->id" :label="'Phân bổ còn dư khoản #'.$source->id" :max="$queries->held($source)" /></div>@endforeach
                    <x-field name="reason" label="Căn cứ phân bổ bổ sung" type="textarea" minlength="5" maxlength="2000" required />
                    <button class="btn btn-primary mt-3"><i data-lucide="save"></i> Ghi phân bổ</button>
                </form>
            </section>
        @endif
    @endif
    @include('portal.finance.history')
@endsection
