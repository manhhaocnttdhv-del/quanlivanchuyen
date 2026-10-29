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
            @can('create', \App\Models\Order::class)
                <a href="{{ route('orders.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tạo vận đơn</a>
            @endcan
        </section>
    @else
        <section class="work-section">
            <h2>Không gian làm việc của bạn</h2>
            <p class="text-secondary mt-3">Vai trò: {{ auth()->user()->roles->pluck('label')->join(', ') }}</p>
            <p class="text-secondary">Phạm vi kho:
                {{ auth()->user()->warehouses->pluck('name')->join(', ') ?: 'Chưa được phân công kho' }}.</p>
        </section>
    @endif
    @can('viewAny', \App\Models\Order::class)
        <section class="work-section">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                <h2>Vận đơn gần đây</h2><a href="{{ route('orders.index') }}">Tất cả vận đơn <i
                        data-lucide="arrow-right"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table work-table">
                    <thead>
                        <tr>
                            <th>Mã vận đơn</th>
                            <th>Người nhận</th>
                            <th>COD</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            <tr>
                                <td><a class="order-code"
                                        href="{{ route('orders.show', $order) }}">{{ $order->tracking_code }}</a></td>
                                <td>{{ $order->recipient_name ?: 'Chưa nhập' }}</td>
                                <td>{{ number_format((int) $order->cod_amount, 0, ',', '.') }}đ</td>
                                <td><span
                                        class="status-badge order-state state-{{ $order->status }}">{{ \App\Models\Order::STATUSES[$order->status] ?? $order->status }}</span>
                                </td>
                        </tr>@empty<tr>
                                <td colspan="4">Chưa có vận đơn.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endcan
@endsection
