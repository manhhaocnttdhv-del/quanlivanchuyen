@extends('layouts.portal')
{{-- Bộ lọc nhân sự giữ qua phân trang; roles/warehouses đã eager load để không query lại theo từng dòng. --}}
@section('heading', 'Tài khoản & phân quyền')
@section('actions')<a class="btn btn-primary" href="{{ route('users.create') }}"><i data-lucide="plus"></i> Tạo tài
    khoản</a>@endsection
@section('content')
    <form class="filter-bar" method="get"><x-field name="q" label="Tìm kiếm" :value="request('q')"
            placeholder="Tên, email, điện thoại" /><x-field name="status" label="Trạng thái" :value="request('status')"
            :options="['' => 'Tất cả', 'active' => 'Hoạt động', 'inactive' => 'Đã khóa']" /><x-field name="role" label="Vai trò" :value="request('role')" :options="['' => 'Tất cả'] + $roles->all()" /><button
            class="btn btn-outline-primary">Lọc</button><a class="btn btn-link" href="{{ route('users.index') }}">Xóa
            lọc</a></form>
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Tài khoản</th>
                    <th>Vai trò</th>
                    <th>Phạm vi kho</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $record)
                    <tr>
                        <td><strong>{{ $record->name }}</strong><small>{{ $record->email }}<br>{{ $record->phone }}</small>
                        </td>
                        <td>{{ $record->roles->pluck('label')->join(', ') }}</td>
                        <td>{{ $record->warehouses->pluck('name')->join(', ') ?: '—' }}</td>
                        <td>{{ $record->status === 'active' ? 'Hoạt động' : 'Đã khóa' }}</td>
                        <td><a class="btn btn-outline-primary" href="{{ route('users.edit', $record) }}"><i
                                    data-lucide="pencil"></i> Sửa</a></td>
                </tr>@empty<tr>
                        <td colspan="5" class="text-center py-5">Không có tài khoản phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>{{ $users->links() }}
@endsection
