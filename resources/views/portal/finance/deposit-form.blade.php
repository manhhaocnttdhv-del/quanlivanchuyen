@extends('layouts.portal')
{{-- Chỉ chọn số tiền shipper còn giữ; token chống gửi lặp, xác nhận thực nhận là bước của người khác. --}}
@section('heading', 'Đề nghị nộp tiền')
@section('content')
    @include('portal.finance.nav', ['section' => 'deposits'])
    <form method="get" class="filter-bar my-4">
        <x-field name="warehouse_id" label="Kho nhận" :options="$warehouses" :value="$warehouse" required />
        <x-field name="shipper_id" label="Shipper nộp" :options="$shippers" :value="$shipper" required />
        <x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" />
        <button class="btn btn-outline-primary"><i data-lucide="search"></i> Xem khoản thu</button>
    </form>
    <form method="post" action="{{ route('finance.deposits.store') }}" class="finance-form">
        @csrf
        <input type="hidden" name="client_token" value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="warehouse_id" value="{{ $warehouse }}"><input type="hidden" name="shipper_id" value="{{ $shipper }}">
        <div class="table-responsive"><table class="table align-middle finance-table">
            <thead><tr><th>Vận đơn / khoản thu</th><th class="text-end">Thực thu hiệu lực</th><th class="text-end">Còn phải nộp</th><th class="text-end">Đề nghị nộp (đ)</th></tr></thead>
            <tbody>@forelse ($sources as $source)
                <tr><td><a href="{{ route('finance.collections.show', $source) }}" class="order-code">{{ $source->order->tracking_code }}</a><small class="d-block">#{{ $source->id }} · {{ \App\Models\DeliveryCollection::TYPES[$source->type] }}</small></td>
                    <td class="text-end" data-label="Thực thu hiệu lực">{{ number_format($queries->effective($source), 0, ',', '.') }}đ</td><td class="text-end" data-label="Còn phải nộp">{{ number_format($queries->held($source), 0, ',', '.') }}đ</td>
                    <td data-label="Đề nghị nộp (đ)"><x-finance-amount group="allocations" :source="$source->id" :label="'Đề nghị nộp khoản #'.$source->id" :max="$queries->held($source)" /></td></tr>
            @empty<tr><td colspan="4" class="text-center py-5 text-secondary">Không có khoản tiền còn phải nộp.</td></tr>@endforelse</tbody>
        </table></div>
        <x-field name="reason" label="Nội dung nộp tiền" type="textarea" rows="3" minlength="5" maxlength="2000" required />
        <button class="btn btn-primary mt-3" @disabled($sources->isEmpty())><i data-lucide="send"></i> Gửi đề nghị</button>
    </form>
    <div class="mt-3">{{ $sources->links() }}</div>
@endsection
