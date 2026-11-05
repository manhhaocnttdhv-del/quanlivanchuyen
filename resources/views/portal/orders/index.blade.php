@extends('layouts.portal')
@section('heading', 'Vận đơn')
@section('actions')
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-primary"
            href="{{ route('orders.manifest', request()->only(['q', 'status', 'shop_id', 'area_id', 'assigned_shipper_id', 'from', 'until'])) }}"><i
                data-lucide="printer"></i> Bảng kê</a>
        @can('create', \App\Models\Order::class)
            <a href="{{ route('orders.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tạo vận đơn</a>
        @endcan
    </div>
@endsection
@section('content')
    <div class="order-status-tabs">
        <a href="{{ route('orders.index') }}" class="{{ !request('status') ? 'active' : '' }}">Tất cả
            <span>{{ $counts->sum() }}</span></a>
        @foreach (['draft', 'submitted', 'needs_info', 'approved', 'cancelled'] as $key)
            <a class="{{ request('status') === $key ? 'active' : '' }}"
                href="{{ route('orders.index', ['status' => $key]) }}">{{ $statuses[$key] }}
                <span>{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </div>
    <form method="get" class="order-filters">
        <x-field name="q" label="Mã vận đơn" :value="request('q')" placeholder="GV..." maxlength="32" />
        <x-field name="status" label="Trạng thái" :value="request('status')" :options="$statuses" placeholder="Tất cả" />
        <x-field name="shop_id" label="Người gửi / cửa hàng" :value="request('shop_id')" :options="$shops" placeholder="Tất cả" />
        <x-field name="area_id" label="Khu vực nhận" :value="request('area_id')" :options="$areas" placeholder="Tất cả" />
        <x-field name="from" label="Tạo từ ngày" type="date" :value="request('from')" />
        <x-field name="until" label="Đến ngày" type="date" :value="request('until')" />
        <x-field name="assigned_shipper_id" label="Shipper" :value="request('assigned_shipper_id')" :options="$shippers" placeholder="Tất cả" />
        <div class="d-flex align-items-end gap-2"><button class="btn btn-primary" aria-label="Lọc vận đơn"
                title="Lọc vận đơn"><i data-lucide="search"></i></button><a class="btn btn-outline-primary"
                href="{{ route('orders.index') }}" aria-label="Xóa bộ lọc" title="Xóa bộ lọc"><i
                    data-lucide="rotate-ccw"></i></a></div>
    </form>
    <div class="list-summary"><span>{{ number_format($orders->total()) }} vận đơn</span><span>COD và cước vận chuyển được
            tính riêng</span></div>
    <div class="table-responsive">
        <table class="table work-table order-table">
            <thead>
                <tr>
                    <th>Vận đơn / người gửi</th>
                    <th>Người nhận</th>
                    <th>Kiện hàng</th>
                    <th>COD / cước</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td><a class="order-code"
                                href="{{ route('orders.show', $order) }}">{{ $order->tracking_code }}</a><small>{{ $order->shop->name }}</small><small>{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</small>
                        </td>
                        <td>{{ $order->recipient_name ?: 'Chưa nhập' }}<small>{{ $order->recipient_phone }}</small><small>{{ $order->area?->name }}</small>
                        </td>
                        <td>{{ $order->parcel_count }}
                            kiện<small>{{ $order->weight_grams ? number_format($order->weight_grams) . ' g' : 'Chưa có cân nặng' }}</small><small>{{ $order->warehouse?->name }}</small>
                        </td>
                        <td class="text-nowrap">{{ number_format((int) $order->cod_amount, 0, ',', '.') }}đ<small>Cước:
                                {{ $order->shipping_fee !== null ? number_format((int) $order->shipping_fee, 0, ',', '.') . 'đ' : 'Chưa chốt' }}</small><small>{{ $order->fee_payer === 'sender' ? 'Người gửi trả phí' : 'Người nhận trả phí' }}</small>
                        </td>
                        <td><span
                                class="status-badge order-state state-{{ $order->status }}">{{ $statuses[$order->status] ?? $order->status }}</span>
                            @if ($order->pendingChange)
                                <small class="text-warning-emphasis">Có đề nghị chờ duyệt</small>
                            @endif
                        </td>
                        <td><a href="{{ route('orders.show', $order) }}" class="btn btn-outline-primary"
                                aria-label="Mở vận đơn {{ $order->tracking_code }}" title="Mở vận đơn"><i
                                    data-lucide="arrow-right"></i></a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="empty-orders"><i data-lucide="package"></i><strong>Chưa có vận đơn phù
                                hợp</strong><span>Thay đổi bộ lọc hoặc tạo vận đơn mới.</span></td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $orders->links() }}
@endsection
