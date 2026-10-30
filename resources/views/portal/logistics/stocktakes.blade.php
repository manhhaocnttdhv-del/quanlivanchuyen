@extends('layouts.portal')
{{-- Tạo snapshot theo kho/vị trí được cấp; danh sách giữ người lập/duyệt và số dòng để truy vết. --}}
@section('heading', 'Kiểm kê kho')
@section('content')
    @can('logistics.warehouse')
        <section class="work-section">
            <h2>Lập kỳ kiểm kê</h2>
            <form method="post" class="narrow-form mt-3" action="{{ route('stocktakes.store') }}">@csrf<div class="row g-3">
                    <div class="col-sm-6"><x-field name="warehouse_id" label="Kho" :options="$warehouses" required /></div>
                    <div class="col-sm-6"><x-field name="location_id" label="Vị trí" :options="['' => 'Toàn kho'] +
                        $locations->mapWithKeys(fn($l) => [$l->id => $l->warehouse->name . ' / ' . $l->code])->all()" /></div>
                    <div class="col-12"><x-field name="reason" label="Nội dung kiểm kê" minlength="5" maxlength="1000"
                            required /></div>
                </div><button class="btn btn-primary mt-3"><i data-lucide="clipboard-list"></i> Lập snapshot kiểm kê</button>
            </form>
        </section>
    @endcan
    <section class="work-section">
        <h2>Biên bản kiểm kê</h2>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Biên bản</th>
                        <th>Kho / vị trí</th>
                        <th>Số kiện</th>
                        <th>Người lập</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stocktakes as $stocktake)
                        <tr>
                            <td><a class="logistics-code"
                                    href="{{ route('stocktakes.show', $stocktake) }}">{{ $stocktake->code }}</a><small
                                    class="d-block text-secondary">{{ $stocktake->snapshot_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</small>
                            </td>
                            <td>{{ $stocktake->warehouse->name }} / {{ $stocktake->location?->code ?? 'Toàn kho' }}</td>
                            <td>{{ $stocktake->lines_count }}</td>
                            <td>{{ $stocktake->creator->name }}</td>
                            <td><span
                                    class="status-badge">{{ ['draft' => 'Đang kiểm đếm', 'submitted' => 'Chờ xác nhận', 'approved' => 'Đã xác nhận'][$stocktake->status] }}</span>
                            </td>
                    </tr>@empty<tr>
                            <td colspan="5" class="py-4 text-secondary">Chưa có biên bản kiểm kê.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{ $stocktakes->links() }}
    </section>
@endsection
