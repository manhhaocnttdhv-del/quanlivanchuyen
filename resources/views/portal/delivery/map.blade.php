@extends('layouts.portal')
@section('heading', 'Bản đồ nhiệm vụ')
@section('actions')
    <a class="btn btn-outline-primary" href="{{ route('delivery.mine') }}"><i data-lucide="clipboard-list"></i> Danh sách nhiệm vụ</a>
@endsection
@section('content')
    <div class="shipper-map-toolbar">
        <nav class="nav nav-underline" aria-label="Loại điểm dừng">
            @foreach (['' => 'Tất cả'] + \App\Models\DeliveryTask::TYPES as $value => $label)
                <a class="nav-link {{ ($filters['type'] ?? '') === $value ? 'active' : '' }}"
                    @if (($filters['type'] ?? '') === $value) aria-current="page" @endif
                    href="{{ route('delivery.map', array_filter(['type' => $value, 'q' => $filters['q'] ?? null])) }}">{{ $label }} <span class="small">{{ $value === '' ? $counts->sum() : ($counts[$value] ?? 0) }}</span></a>
            @endforeach
        </nav>
        <form method="get" action="{{ route('delivery.map') }}" class="d-flex gap-2 delivery-search">
            @if (!empty($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
            <input class="form-control" name="q" aria-label="Mã vận đơn" placeholder="Tìm mã vận đơn" value="{{ $filters['q'] ?? '' }}" maxlength="40">
            <button class="btn btn-outline-primary icon-button" aria-label="Tìm điểm dừng" title="Tìm điểm dừng"><i data-lucide="search"></i></button>
        </form>
    </div>
    @if ($selected)
        <div class="shipper-map-layout">
            <section class="shipper-map-stops" aria-label="Nhiệm vụ đang thực hiện">
                <h2 class="h6">Điểm dừng <span class="text-secondary">{{ $tasks->total() }}</span></h2>
                <div class="shipper-stop-list">
                    @foreach ($tasks as $task)
                        <a class="shipper-stop {{ $selected->id === $task->id ? 'active' : '' }}"
                            @if ($selected->id === $task->id) aria-current="true" @endif
                            href="{{ route('delivery.map', array_merge($filters, ['task' => $task->id])) }}">
                            <span class="small text-secondary">{{ $stops[$task->id]['phase'] }}</span>
                            <strong class="logistics-code">{{ $task->order->tracking_code }}</strong>
                            <span>{{ $stops[$task->id]['name'] }}</span>
                            <span class="small">{{ $stops[$task->id]['address'] }}</span>
                            <span class="small text-secondary">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }} · {{ $task->warehouse->name }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="mt-3">{{ $tasks->links() }}</div>
            </section>
            <section class="shipper-map-detail" aria-label="Điểm tiếp theo">
                <div class="shipper-map-destination">
                    <div><span class="small text-secondary">{{ $stop['phase'] }}</span><h2 class="h5">{{ $stop['name'] }}</h2><p>{{ $stop['address'] }} · {{ $stop['area'] }}</p></div>
                    <span class="status-badge">{{ \App\Models\DeliveryTask::TYPES[$selected->type] }}</span>
                </div>
                @include('portal.delivery.partials.map-simulation')
                <div class="shipper-map-actions">
                    <span class="small text-secondary logistics-code">{{ $selected->order->tracking_code }} · {{ $selected->order->parcel_count }} kiện</span>
                    <div class="d-flex flex-wrap gap-2">
                        @if ($stop['phone'])<a class="btn btn-outline-primary" href="tel:{{ $stop['phone'] }}"><i data-lucide="phone"></i> Gọi điện</a>@endif
                        @if ($stop['directions'])<a class="btn btn-outline-primary" href="{{ $stop['directions'] }}" target="_blank" rel="noopener noreferrer"><i data-lucide="route"></i> Chỉ đường</a>@endif
                        <a class="btn btn-primary" href="{{ route('logistics.show', $selected) }}"><i data-lucide="arrow-right"></i> Mở nhiệm vụ</a>
                    </div>
                </div>
            </section>
        </div>
    @else
        <div class="empty-state py-5"><i data-lucide="map-pin"></i><h2 class="h5 mt-3">Chưa có điểm dừng phù hợp</h2><a href="{{ route('delivery.map') }}">Tất cả nhiệm vụ đang thực hiện</a></div>
    @endif
@endsection
