@php
    $tabs = ['collections' => 'Sổ tiền thu', 'deposits' => 'Nộp tiền', 'reconciliations' => 'Đối soát', 'fees' => 'Nợ phí', 'receipts' => 'Thu phí', 'adjustments' => 'Điều chỉnh'];
    $financeUser = auth()->user();
@endphp
<nav class="order-status-tabs finance-tabs" aria-label="Sổ tài chính">
    @foreach ($tabs as $key => $label)
        @if ($financeUser->hasPermission('finance.manage') || ($financeUser->hasRole('shipper') && in_array($key, ['collections', 'deposits'])) || ($financeUser->shop && in_array($key, ['collections', 'reconciliations', 'fees', 'receipts'])))
            <a href="{{ route('finance.index', $key) }}" class="{{ ($section ?? '') === $key ? 'active' : '' }}" @if (($section ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>
        @endif
    @endforeach
</nav>
