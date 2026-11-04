@extends('layouts.portal')
@section('heading', 'Tồn kho & sai lệch')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('stocktakes.index') }}">
    <i data-lucide="clipboard-list"></i> Kiểm kê</a>@endsection
@section('content')
    <form method="get" class="filter-bar"><x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" /><x-field
            name="warehouse_id" label="Kho" :options="['' => 'Tất cả kho'] + $warehouses->all()" :value="$filters['warehouse_id'] ?? ''" /><x-field name="location_id" label="Vị trí"
            :options="['' => 'Tất cả vị trí'] +
                $locations->mapWithKeys(fn($l) => [$l->id => $l->warehouse->name . ' / ' . $l->code])->all()" :value="$filters['location_id'] ?? ''" />
        <div class="form-check mb-2"><input id="hold-filter" name="hold" value="1" class="form-check-input"
                type="checkbox" @checked($filters['hold'] ?? false)><label class="form-check-label" for="hold-filter">Chỉ kiện đang
                giữ</label></div><button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button>
    </form>
    <section class="work-section">
        <div class="d-flex justify-content-between">
            <h2>Kiện đang tại kho</h2><small>{{ $items->total() }} kiện</small>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Vận đơn / kiện</th>
                        <th>Vị trí</th>
                        <th>Cân thực tế</th>
                        <th>Tình trạng</th>
                        <th>Giữ kiện</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td><a class="logistics-code"
                                    href="{{ route('logistics.order', $item->order) }}">{{ $item->order->tracking_code }}</a><small
                                    class="d-block">Kiện #{{ $item->parcel_number }}</small></td>
                            <td>{{ $item->location?->code }}</td>
                            <td>{{ number_format($item->actual_weight_grams) }} g</td>
                            <td>{{ $item->condition === 'damaged' ? 'Hư hỏng' : 'Nguyên vẹn' }}</td>
                            <td>
                                @if ($item->hold_count)
                                    <span class="status-badge bg-danger-subtle text-danger">{{ $item->hold_count }} sai
                                    lệch</span>@else<span class="text-secondary small">Không</span>
                                @endif
                            </td>
                    </tr>@empty<tr>
                            <td colspan="5" class="py-4 text-secondary">Chưa có kiện trong kho ở bộ lọc này.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{ $items->links() }}
    </section>
    <section class="work-section">
        <h2>Hồ sơ sai lệch</h2>@include('portal.logistics.discrepancies'){{ $discrepancies->links() }}
    </section>
    @can('logistics.warehouse')
        <section class="work-section">
            <h2>Vị trí trong kho</h2>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kho</th>
                            <th>Mã vị trí</th>
                            <th>Tên</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($locations as $location)
                            <tr>
                                <td>{{ $location->warehouse->name }}</td>
                                <td>{{ $location->code }}</td>
                                <td>{{ $location->name }}</td>
                        </tr>@empty<tr>
                                <td colspan="3">Chưa có vị trí.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <details>
                <summary>Thêm vị trí</summary>
                <form method="post" class="narrow-form mt-3" action="{{ route('logistics.location') }}">@csrf<div
                        class="row g-3">
                        <div class="col-12"><x-field name="warehouse_id" id="new-location-warehouse" label="Kho"
                                :options="$warehouses" required /></div>
                        <div class="col-sm-4"><x-field name="code" label="Mã vị trí" placeholder="A-01"
                                pattern="[A-Z0-9-]{1,40}" maxlength="40" required /></div>
                        <div class="col-sm-8"><x-field name="name" label="Tên vị trí" maxlength="150" required /></div>
                    </div><button class="btn btn-primary mt-3"><i data-lucide="plus"></i> Thêm vị trí</button></form>
            </details>
        </section>
    @endcan
@endsection
