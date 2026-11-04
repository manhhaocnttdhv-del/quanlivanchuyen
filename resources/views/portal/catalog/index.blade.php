@extends('layouts.portal')
@section('heading', 'Khu vực & kho vận hành')
@section('content')
    <section class="work-section">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
            <h2>Kho</h2><a class="btn btn-primary" href="{{ route('warehouses.create') }}"><i data-lucide="plus"></i> Thêm
                kho</a>
        </div>
        <div class="table-responsive">
            <table class="table work-table">
                <thead>
                    <tr>
                        <th>Mã kho</th>
                        <th>Tên kho</th>
                        <th>Khu vực</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($warehouses as $warehouse)
                        <tr>
                            <td>{{ $warehouse->code }}</td>
                            <td>{{ $warehouse->name }}<small>{{ $warehouse->address }}</small></td>
                            <td>{{ $warehouse->area->name }}</td>
                            <td>{{ $warehouse->is_active ? 'Hoạt động' : 'Ngừng dùng' }}</td>
                            <td><a class="btn btn-outline-primary" href="{{ route('warehouses.edit', $warehouse) }}">Sửa</a>
                            </td>
                    </tr>@empty<tr>
                            <td colspan="5">Chưa có kho.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="work-section">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
            <h2>Khu vực phục vụ</h2><a class="btn btn-outline-primary" href="{{ route('areas.create') }}"><i
                    data-lucide="plus"></i> Thêm khu vực</a>
        </div>
        <div class="table-responsive">
            <table class="table work-table">
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên khu vực</th>
                        <th>Miền</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($areas as $area)
                        <tr>
                            <td>{{ $area->code }}</td>
                            <td>{{ $area->name }}</td>
                            <td>{{ ['north' => 'Bắc', 'central' => 'Trung', 'south' => 'Nam'][$area->region] }}</td>
                            <td>{{ $area->is_active ? 'Phục vụ' : 'Ngừng phục vụ' }}</td>
                            <td><a class="btn btn-outline-primary" href="{{ route('areas.edit', $area) }}">Sửa</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
