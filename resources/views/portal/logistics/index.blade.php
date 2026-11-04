@extends('layouts.portal')
@section('heading', auth()->user()->hasRole('shipper') ? 'Nhiệm vụ của tôi' : 'Điều phối & bàn giao')
@section('content')
    <form class="filter-bar" method="get">
        <x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" />
        <x-field name="warehouse_id" label="Kho" :value="$filters['warehouse_id'] ?? ''" :options="['' => 'Tất cả kho'] + $warehouses->all()" />
        <x-field name="type" label="Loại nhiệm vụ" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryTask::TYPES" />
        <x-field name="status" label="Trạng thái" :value="$filters['status'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryTask::STATUSES" />
        <button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button>
        <a class="btn btn-outline-secondary" href="{{ route('logistics.index') }}" title="Xóa bộ lọc"
            aria-label="Xóa bộ lọc"><i data-lucide="rotate-ccw"></i></a>
    </form>
    @if ($waiting)
        <section class="work-section">
            <div class="d-flex justify-content-between gap-2">
                <h2>Chờ phân công</h2><span class="text-secondary small">{{ $waiting->total() }} vận đơn</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Vận đơn</th>
                            <th>Shop / khu vực</th>
                            <th>Kiện</th>
                            <th>Trạng thái</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($waiting as $order)
                            <tr>
                                <td class="logistics-code">{{ $order->tracking_code }}</td>
                                <td>{{ $order->shop->name }}<small
                                        class="d-block text-secondary">{{ $order->sender_snapshot['area'] ?? '' }} →
                                        {{ $order->recipient_snapshot['area'] ?? '' }}</small></td>
                                <td>{{ $order->parcel_count }}</td>
                                <td>{{ \App\Models\Order::STATUSES[$order->status] }}</td>
                                <td><a class="btn btn-sm btn-outline-primary"
                                        href="{{ route('logistics.order', $order) }}"><i data-lucide="truck"></i> Phân
                                        công</a></td>
                            </tr>
                        @empty<tr>
                                <td colspan="5" class="text-secondary py-4">Chưa có vận đơn đủ điều kiện phân công.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>{{ $waiting->links() }}
        </section>
    @endif
    <section class="work-section">
        <div class="d-flex justify-content-between gap-2">
            <h2>Nhiệm vụ vận chuyển</h2><span class="text-secondary small">{{ $tasks->total() }} nhiệm vụ</span>
        </div>
        <div class="task-list">
            @forelse ($tasks as $task)
                <article class="task-row">
                    <div><a class="logistics-code fw-semibold"
                            href="{{ route('logistics.show', $task) }}">{{ $task->order->tracking_code }}</a><small
                            class="d-block text-secondary mt-1">#{{ $task->id }} ·
                            {{ \App\Models\DeliveryTask::TYPES[$task->type] }} · {{ $task->warehouse->name }}</small>
                    </div>
                    <div>{{ $task->shipper->name }}<small class="d-block text-secondary">{{ $task->order->parcel_count }}
                            kiện · {{ $task->order->shop->name }}</small></div>
                    <span
                        class="status-badge {{ $task->status === 'recall_pending' ? 'bg-danger-subtle text-danger' : '' }}">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }}</span>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('logistics.show', $task) }}">Mở nhiệm vụ <i
                            data-lucide="arrow-right"></i></a>
                </article>
            @empty<p class="text-secondary py-4">Chưa có nhiệm vụ trong bộ lọc này.</p>
            @endforelse
        </div>{{ $tasks->links() }}
    </section>
@endsection
