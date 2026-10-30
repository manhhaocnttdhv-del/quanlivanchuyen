@extends('layouts.portal')
@section('heading', ['collections' => 'Sổ tiền thu', 'deposits' => 'Phiếu nộp tiền', 'reconciliations' => 'Đối soát shop', 'fees' => 'Công nợ phí', 'receipts' => 'Phiếu thu phí', 'adjustments' => 'Điều chỉnh tài chính'][$section])
@section('actions')
    @if ($section === 'deposits' && ($staff || auth()->user()->hasRole('shipper')))
        <a class="btn btn-primary" href="{{ route('finance.deposits.create') }}"><i data-lucide="plus"></i> Đề nghị nộp tiền</a>
    @elseif ($staff && in_array($section, ['reconciliations', 'receipts', 'adjustments']))
        <a class="btn btn-primary" href="{{ route('finance.'.$section.'.create') }}"><i data-lucide="plus"></i> {{ ['reconciliations' => 'Lập đối soát', 'receipts' => 'Thu nợ phí', 'adjustments' => 'Lập điều chỉnh'][$section] }}</a>
    @endif
@endsection
@section('content')
    @include('portal.finance.nav')
    @if ($section === 'collections')
        <div class="finance-summary">
            @foreach (\App\Models\DeliveryCollection::TYPES as $type => $label)
                @php($total = $totals->firstWhere('type', $type))
                <div><span>{{ $label }}</span><strong>{{ number_format((int) ($total->held ?? 0), 0, ',', '.') }}đ</strong><small>Shipper còn giữ · Đã nộp {{ number_format((int) ($total->deposited ?? 0), 0, ',', '.') }}đ</small></div>
            @endforeach
        </div>
    @endif
    <form method="get" class="filter-bar my-4">
        <x-field name="q" :label="in_array($section, ['collections', 'fees']) ? 'Mã vận đơn' : 'Mã chứng từ'" :value="$filters['q'] ?? ''" maxlength="40" />
        @if ($warehouses->isNotEmpty())<x-field name="warehouse_id" label="Kho" :value="$filters['warehouse_id'] ?? ''" :options="['' => 'Tất cả kho'] + $warehouses->all()" />@endif
        @if ($statuses)<x-field name="status" label="Trạng thái" :value="$filters['status'] ?? ''" :options="['' => 'Tất cả'] + $statuses" />@endif
        @if ($section === 'collections')<x-field name="type" label="Khoản thu" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryCollection::TYPES" />@endif
        <x-field name="from" type="date" label="Từ ngày" :value="$filters['from'] ?? ''" />
        <x-field name="until" type="date" label="Đến ngày" :value="$filters['until'] ?? ''" />
        <button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button>
        <a href="{{ route('finance.index', $section) }}" class="btn icon-button" aria-label="Bỏ lọc" title="Bỏ lọc"><i data-lucide="rotate-ccw"></i></a>
    </form>
    <p class="small text-secondary">{{ number_format($records->total()) }} kết quả</p>
    <div class="table-responsive">
        <table class="table align-middle finance-table">
            <thead><tr>
                <th>{{ in_array($section, ['collections', 'fees']) ? 'Nguồn tiền' : 'Chứng từ' }}</th><th>Đối tượng</th>
                @if ($section === 'collections')<th>Khoản thu</th><th class="text-end">Gốc / điều chỉnh</th><th class="text-end">Đã nộp</th><th class="text-end">Còn giữ</th>
                @elseif ($section === 'fees')<th>Loại phí</th><th class="text-end">Phí gốc</th><th class="text-end">Đã thu / giữ đối soát</th><th class="text-end">Còn nợ khả dụng</th>
                @else<th>Trạng thái</th><th class="text-end">Số tiền</th><th>Người lập</th>@endif
            </tr></thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        @if ($section === 'collections')
                            <td><a class="order-code" href="{{ route('finance.collections.show', $record) }}">{{ $record->order->tracking_code }}</a><small class="d-block text-secondary">#{{ $record->id }} · {{ $record->collected_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</small></td>
                            <td>{{ $record->shipper->name }}<small class="d-block text-secondary">{{ $record->order->shop->name }}</small></td>
                            <td>{{ \App\Models\DeliveryCollection::TYPES[$record->type] }}@if ($record->order->financial_hold)<small class="d-block text-danger">Khóa tài chính</small>@endif</td>
                            <td class="text-end">{{ number_format((int) $record->amount, 0, ',', '.') }}đ<small class="d-block text-secondary">{{ number_format((int) $record->correction_amount, 0, ',', '.') }}đ</small></td>
                            <td class="text-end">{{ number_format((int) $record->deposited_amount, 0, ',', '.') }}đ</td>
                            <td class="text-end fw-semibold">{{ number_format($queries->held($record), 0, ',', '.') }}đ</td>
                        @elseif ($section === 'fees')
                            <td><a class="order-code" href="{{ route('finance.fees.show', $record) }}">{{ $record->order?->tracking_code ?? $record->reconciliation?->code }}</a><small class="d-block text-secondary">#{{ $record->id }} · {{ $record->accrued_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') }}</small></td>
                            <td>{{ $record->shop->name }}</td><td>{{ \App\Models\Receivable::TYPES[$record->type] }}</td>
                            <td class="text-end">{{ number_format((int) $record->amount, 0, ',', '.') }}đ</td>
                            <td class="text-end">{{ number_format((int) $record->received_amount, 0, ',', '.') }}đ<small class="d-block">{{ number_format((int) $record->reserved_amount, 0, ',', '.') }}đ</small></td>
                            <td class="text-end fw-semibold">{{ number_format($queries->available($record), 0, ',', '.') }}đ</td>
                        @else
                            <td><a class="order-code" href="{{ route('finance.'.$section.'.show', $record) }}">{{ $record->code }}</a><small class="d-block text-secondary">{{ $record->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</small></td>
                            <td>{{ $section === 'deposits' ? $record->shipper->name : ($section === 'adjustments' ? $record->order->tracking_code : $record->shop->name) }}<small class="d-block text-secondary">{{ $record->warehouse->name }}</small></td>
                            <td><span class="status-badge state-{{ $record->status }}">{{ $record::STATUSES[$record->status] }}</span></td>
                            <td class="text-end fw-semibold">{{ number_format((int) ($section === 'deposits' ? $record->requested_amount : ($section === 'reconciliations' ? $record->payable_amount : $record->amount)), 0, ',', '.') }}đ
                                @if ($section === 'deposits')<small class="d-block text-secondary">Nhận {{ number_format((int) $record->received_amount, 0, ',', '.') }}đ</small>@endif
                                @if ($section === 'reconciliations' && (int) $record->debt_amount > 0)<small class="d-block text-danger">Nợ phí {{ number_format((int) $record->debt_amount, 0, ',', '.') }}đ</small>@endif
                            </td><td>{{ $record->creator->name }}</td>
                        @endif
                    </tr>
                @empty<tr><td colspan="6" class="text-center text-secondary py-5">Chưa có dữ liệu phù hợp.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $records->links() }}
@endsection
