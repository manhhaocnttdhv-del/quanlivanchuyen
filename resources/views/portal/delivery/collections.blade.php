@extends('layouts.portal')
@section('heading', 'Sổ thu giao hàng')
@section('content')
    <p class="text-secondary">
        Tiền shipper ghi nhận đã thu tại lúc giao / hoàn. Trạng thái nộp quỹ và đối soát chưa được xác nhận.</p>
    <dl class="delivery-totals">
        @foreach (\App\Models\DeliveryCollection::TYPES as $type => $label)
            <div>
                <dt>{{ $label }}</dt>
                <dd>{{ number_format((int) ($totals[$type]->amount ?? 0), 0, ',', '.') }}đ</dd>
            </div>
        @endforeach
    </dl>
    <form method="get" class="filter-bar mt-4">
        <x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" maxlength="40" />
        <x-field name="type" label="Khoản thu" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryCollection::TYPES" />
        <x-field name="financial_status" label="Đối chiếu" :value="$filters['financial_status'] ?? ''" :options="['' => 'Tất cả', 'matched' => 'Khớp tiền', 'disputed' => 'Chênh lệch']" />
        <x-field name="from" type="date" label="Từ ngày" :value="$filters['from'] ?? ''" />
        <x-field name="until" type="date" label="Đến ngày" :value="$filters['until'] ?? ''" />
        <button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button>
        <a href="{{ route('delivery.collections') }}" class="btn btn-outline-primary">Bỏ lọc</a>
    </form>
    <div class="table-responsive mt-4">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Vận đơn / thời gian</th>
                    <th>Người thu</th>
                    <th>Khoản thu</th>
                    <th class="text-end">Dự kiến</th>
                    <th class="text-end">Thực thu</th>
                    <th>Đối chiếu</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($collections as $collection)
                    <tr>
                        <td>
                            @can('view', $collection->order)
                                <a
                                    href="{{ route('orders.show', $collection->order) }}">{{ $collection->order->tracking_code }}</a>
                            @else
                                {{ $collection->order->tracking_code }}
                            @endcan
                            <small class="d-block text-secondary">
                                {{ $collection->collected_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }} · Lần
                                #{{ $collection->delivery_attempt_id }}</small>
                        </td>
                        <td>{{ $collection->shipper->name }}</td>
                        <td>{{ \App\Models\DeliveryCollection::TYPES[$collection->type] }}</td>
                        <td class="text-end text-nowrap">
                            {{ number_format((int) $collection->expected_amount, 0, ',', '.') }}đ</td>
                        <td class="text-end text-nowrap fw-semibold">
                            {{ number_format((int) $collection->amount, 0, ',', '.') }}đ</td>
                        <td><span
                                class="status-badge {{ $collection->financial_status === 'disputed' ? 'text-danger' : '' }}">{{ $collection->financial_status === 'disputed' ? 'Chênh lệch' : 'Khớp tiền' }}</span>
                            @if ($collection->financial_status === 'disputed')
                                <p class="small mb-0 mt-1">{{ $collection->attempt->financial_reason }}</p>
                            @endif
                        </td>
                    </tr>
                @empty<tr>
                        <td colspan="6" class="text-center text-secondary py-5">Chưa có khoản thu phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $collections->links() }}
@endsection
