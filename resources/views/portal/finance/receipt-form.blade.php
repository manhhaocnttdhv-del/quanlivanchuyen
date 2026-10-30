@extends('layouts.portal')
@section('heading', 'Thu nợ phí shop')
@section('content')
    @include('portal.finance.nav', ['section' => 'receipts'])
    <form method="get" class="filter-bar my-4">
        <x-field name="warehouse_id" label="Kho" :options="$warehouses" :value="$filters['warehouse_id']" required />
        <x-field name="shop_id" label="Shop" :options="$shops" :value="$filters['shop_id']" required />
        <x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" />
        <button class="btn btn-outline-primary"><i data-lucide="search"></i> Lấy công nợ</button>
    </form>
    <form method="post" action="{{ route('finance.receipts.store') }}" enctype="multipart/form-data" class="finance-form">
        @csrf<input type="hidden" name="client_token" value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="warehouse_id" value="{{ $filters['warehouse_id'] }}"><input type="hidden" name="shop_id" value="{{ $filters['shop_id'] }}">
        <div class="table-responsive"><table class="table align-middle finance-table"><thead><tr><th>Nguồn phí</th><th class="text-end">Còn nợ khả dụng</th><th class="text-end">Phân bổ thu (đ)</th></tr></thead><tbody>
            @forelse ($fees as $fee)<tr><td>{{ $fee->order?->tracking_code ?? $fee->reconciliation?->code }}<small class="d-block">#{{ $fee->id }} · {{ \App\Models\Receivable::TYPES[$fee->type] }}</small></td><td class="text-end" data-label="Còn nợ khả dụng">{{ number_format($queries->available($fee), 0, ',', '.') }}đ</td><td data-label="Phân bổ thu (đ)"><x-finance-amount group="allocations" :source="$fee->id" :label="'Thu nợ phí #'.$fee->id" :max="$queries->available($fee)" /></td></tr>
            @empty<tr><td colspan="3" class="text-center text-secondary py-5">Không có khoản nợ phí khả dụng.</td></tr>@endforelse
        </tbody></table></div>
        <div class="row g-3"><div class="col-md-6"><x-field name="amount" type="number" label="Tổng tiền thực nhận (đ)" min="1" max="9999999999999" step="1" required /></div><div class="col-md-6"><x-field name="transaction_reference" label="Mã biên nhận / ngân hàng" minlength="4" maxlength="100" required /></div></div>
        <x-field name="reason" label="Nội dung thu phí" type="textarea" rows="3" minlength="5" maxlength="2000" required />
        <x-field name="evidence" label="Chứng từ thu tiền" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
        <button class="btn btn-primary mt-3" @disabled($fees->isEmpty())><i data-lucide="save"></i> Ghi nhận thu phí</button>
    </form>
    <div class="mt-3">{{ $fees->links() }}</div>
@endsection
