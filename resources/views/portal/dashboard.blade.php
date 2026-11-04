@extends('layouts.portal')
@section('heading', 'Tổng quan')
@section('content')
    @if ($stats)
        <div class="row g-3 mb-4">
            @foreach ($stats as $label => $value)
                <div class="col-sm-6 col-xl-3">
                    <div class="metric"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                </div>
            @endforeach
        </div>
    @endif
    @if ($shop)
        <section class="work-section">
            <h2>{{ $shop->name }}</h2>
            <p class="mt-2"><span class="status-badge">{{ \App\Models\Shop::STATUSES[$shop->status] }}</span></p>
            @if ($shop->review_reason)
                <p>{{ $shop->review_reason }}</p>
            @endif
            <dl class="detail-grid">
                <dt>Địa chỉ</dt>
                <dd>{{ $shop->address }}</dd>
                <dt>Ngân hàng COD</dt>
                <dd>{{ $shop->currentBankAccount?->bank_name ?? 'Chưa đăng ký' }}
                    {{ $shop->currentBankAccount?->masked_number }} ·
                    {{ $shop->currentBankAccount?->verified_at ? 'Đã xác minh' : 'Chờ xác minh' }}</dd>
            </dl>
            <a href="{{ route('shop.profile') }}" class="btn btn-outline-primary">Quản lý cửa hàng</a>
            @if (Route::has('orders.create'))
                <a href="{{ route('orders.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tạo vận đơn</a>
            @endif
        </section>
    @else
        <section class="work-section">
            <h2>Không gian làm việc của bạn</h2>
            <p class="text-secondary mt-3">Vai trò: {{ auth()->user()->roles->pluck('label')->join(', ') }}</p>
            <p class="text-secondary">Phạm vi kho:
                {{ auth()->user()->warehouses->pluck('name')->join(', ') ?: 'Chưa được phân công kho' }}.</p>
        </section>
    @endif
@endsection
