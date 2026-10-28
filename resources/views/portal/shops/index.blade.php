@extends('layouts.portal')
@section('heading', 'Duyệt cửa hàng & ngân hàng')
@section('content')
    <form class="filter-bar" method="get"><x-field name="q" label="Tên cửa hàng" :value="request('q')" /><x-field
            name="status" label="Trạng thái" :value="request('status')" :options="['' => 'Tất cả'] + \App\Models\Shop::STATUSES" /><button
            class="btn btn-outline-primary">Lọc</button><a class="btn btn-link" href="{{ route('shops.index') }}">Xóa lọc</a>
    </form>
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Cửa hàng</th>
                    <th>Chủ shop</th>
                    <th>Khu vực</th>
                    <th>Hồ sơ</th>
                    <th>Ngân hàng</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($shops as $shop)
                    <tr>
                        <td>{{ $shop->name }}</td>
                        <td>{{ $shop->user->name }}<small>{{ $shop->user->phone }}</small></td>
                        <td>{{ $shop->area->name }}</td>
                        <td><span class="status-badge">{{ \App\Models\Shop::STATUSES[$shop->status] }}</span></td>
                        <td>{{ $shop->currentBankAccount?->verified_at ? 'Đã xác minh' : 'Chưa xác minh' }}</td>
                        <td><a class="btn btn-outline-primary" href="{{ route('shops.show', $shop) }}">Mở hồ sơ</a></td>
                </tr>@empty<tr>
                        <td colspan="6" class="text-center py-5">Chưa có hồ sơ phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>{{ $shops->links() }}
@endsection
