@extends('layouts.portal')
@section('heading', $waiting ? 'Trung tâm vận hành' : 'Nhiệm vụ của tôi')
@section('content')
    @if ($waiting)
        <nav class="nav nav-underline mb-3" aria-label="Không gian vận hành">
            <a class="nav-link {{ $workView ? 'active' : '' }}" @if ($workView) aria-current="page" @endif
                href="{{ route('logistics.index', array_merge(request()->only(['q', 'warehouse_id', 'queue']), ['view' => 'work'])) }}">Công việc tại kho</a>
            <a class="nav-link {{ !$workView ? 'active' : '' }}" @if (!$workView) aria-current="page" @endif
                href="{{ route('logistics.index', array_merge(request()->only(['q', 'warehouse_id']), ['view' => 'tasks'])) }}">Nhiệm vụ vận chuyển</a>
        </nav>
    @endif
    <form class="filter-bar operations-filters" method="get">
        <input type="hidden" name="view" value="{{ $workView ? 'work' : 'tasks' }}">
        <x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" placeholder="GV..." maxlength="32" />
        <x-field name="warehouse_id" label="Kho phụ trách" :value="$filters['warehouse_id'] ?? ''" :options="['' => 'Tất cả kho'] + $warehouses->all()" />
        @if ($workView)
            <div class="operations-queue-selector d-md-none"><x-field name="queue" label="Bước xử lý" :value="$queue"
                :options="collect($queues)->mapWithKeys(fn ($label, $key) => [$key => $label.' ('.$queueCounts[$key].')'])->all()" /></div>
        @endif
        @unless ($workView)
            <x-field name="type" label="Loại nhiệm vụ" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryTask::TYPES" />
            <x-field name="status" label="Trạng thái" :value="$filters['status'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\DeliveryTask::STATUSES" />
        @endunless
        <div class="d-flex gap-2">
            <button class="btn btn-primary icon-button" title="Lọc công việc" aria-label="Lọc công việc"><i data-lucide="search"></i></button>
            <a class="btn btn-outline-secondary icon-button" href="{{ route('logistics.index', ['view' => $workView ? 'work' : 'tasks']) }}" title="Xóa bộ lọc" aria-label="Xóa bộ lọc"><i data-lucide="rotate-ccw"></i></a>
        </div>
    </form>
    @if ($workView)
        <nav class="operations-queues d-none d-md-flex" aria-label="Bước xử lý vận đơn">
            @foreach ($queues as $key => $label)
                <a class="{{ $queue === $key ? 'active' : '' }}" @if ($queue === $key) aria-current="page" @endif
                    href="{{ route('logistics.index', array_merge(request()->only(['q', 'warehouse_id']), ['view' => 'work', 'queue' => $key])) }}">
                    <span>{{ $label }}</span><strong>{{ $queueCounts[$key] }}</strong>
                </a>
            @endforeach
        </nav>
        @if ($incomingTransfers && ($incomingTransfers->isNotEmpty() || $queue === 'receipt'))
            <section class="work-section operations-work-section">
                <div class="operations-section-heading"><h2>Chờ kho nhận kiểm đếm</h2><span>{{ $incomingTransfers->total() }} phiếu chuyển</span></div>
                <div class="table-responsive">
                    <table class="table work-table operations-table">
                        <thead><tr><th>Vận đơn / phiếu chuyển</th><th>Tuyến kho</th><th>Kiện đã nhận</th><th>Trạng thái</th><th><span class="visually-hidden">Thao tác</span></th></tr></thead>
                        <tbody>
                            @forelse ($incomingTransfers as $transfer)
                                <tr>
                                    <td><a class="logistics-code fw-semibold" href="{{ route('transfers.show', $transfer) }}">{{ $transfer->order->tracking_code }}</a><small>{{ $transfer->code }}</small></td>
                                    <td data-label="Tuyến kho">{{ $transfer->source->name }}<small><i data-lucide="arrow-right"></i> {{ $transfer->destination->name }}</small></td>
                                    <td data-label="Kiện đã nhận">{{ $transfer->received_count }} / {{ $transfer->lines_count }} kiện</td>
                                    <td data-label="Trạng thái"><span class="status-badge {{ $transfer->status === 'partial' ? 'bg-warning-subtle text-warning-emphasis' : '' }}">{{ \App\Models\WarehouseTransfer::STATUSES[$transfer->status] }}</span></td>
                                    <td><a class="btn btn-primary" href="{{ route('transfers.show', $transfer) }}"><i data-lucide="package-check"></i><span>Kiểm đếm</span></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-secondary py-4">Không có phiếu chuyển chờ nhận trong phạm vi này.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $incomingTransfers->links() }}
            </section>
        @endif
        @unless ($queue === 'receipt')
            <section class="work-section operations-work-section">
                <div class="operations-section-heading"><h2>{{ $queue === 'all' ? 'Vận đơn đang xử lý' : $queues[$queue] }}</h2><span>{{ $waiting->total() }} vận đơn</span></div>
                <div class="table-responsive">
                    <table class="table work-table operations-table">
                        <thead><tr><th>Vận đơn / người gửi</th><th>Tuyến / kho phụ trách</th><th>Kiện / COD</th><th>Bước hiện tại</th><th><span class="visually-hidden">Thao tác</span></th></tr></thead>
                        <tbody>
                            @forelse ($waiting as $order)
                                @php
                                    $state = $workflow[$order->id];
                                    $reviewable = ($order->status === 'submitted' || $order->pendingChange) && $reviewAccess;
                                    $url = $state['pendingHandover'] ? route('logistics.handover', $state['pendingHandover'])
                                        : ($state['activeTask'] ? route('logistics.show', $state['activeTask']) : ($reviewable ? route('orders.show', $order) : route('logistics.order', $order)));
                                @endphp
                                <tr>
                                    <td><a class="logistics-code fw-semibold" href="{{ $url }}">{{ $order->tracking_code }}</a><small>{{ $order->shop->name }}</small></td>
                                    <td data-label="Tuyến / kho">{{ $order->sender_snapshot['area'] ?? '' }} → {{ $order->recipient_snapshot['area'] ?? '' }}<small>{{ $order->warehouse->name }}</small></td>
                                    <td data-label="Kiện / COD">{{ $order->parcel_count }} kiện<small>COD {{ number_format((int) $order->cod_amount, 0, ',', '.') }}đ</small></td>
                                    <td data-label="Bước hiện tại"><strong class="operations-current">{{ $state['label'] }}</strong><small>{{ $order->updated_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</small></td>
                                    <td><a class="btn btn-outline-primary" href="{{ $url }}"><span>{{ !$reviewable && in_array($state['action'], ['Xét duyệt', 'Xét đề nghị']) ? 'Mở xử lý' : $state['action'] }}</span><i data-lucide="arrow-right"></i></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-secondary py-4">Không có vận đơn trong hàng đợi này.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $waiting->links() }}
            </section>
        @endunless
    @else
        <section class="work-section">
            <div class="operations-section-heading"><h2>Nhiệm vụ vận chuyển</h2><span>{{ $tasks->total() }} nhiệm vụ</span></div>
            <div class="task-list">
                @forelse ($tasks as $task)
                    <article class="task-row">
                        <div><a class="logistics-code fw-semibold" href="{{ route('logistics.show', $task) }}">{{ $task->order->tracking_code }}</a><small class="d-block text-secondary mt-1">#{{ $task->id }} · {{ \App\Models\DeliveryTask::TYPES[$task->type] }} · {{ $task->warehouse->name }}</small></div>
                        <div>{{ $task->shipper->name }}<small class="d-block text-secondary">{{ $task->order->parcel_count }} kiện · {{ $task->order->shop->name }}</small></div>
                        <span class="status-badge {{ $task->status === 'recall_pending' ? 'bg-danger-subtle text-danger' : '' }}">{{ \App\Models\DeliveryTask::STATUSES[$task->status] }}</span>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('logistics.show', $task) }}">Mở nhiệm vụ <i data-lucide="arrow-right"></i></a>
                    </article>
                @empty
                    <p class="text-secondary py-4">Chưa có nhiệm vụ trong bộ lọc này.</p>
                @endforelse
            </div>
            {{ $tasks->links() }}
        </section>
    @endif
@endsection
