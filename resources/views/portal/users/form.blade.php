@extends('layouts.portal')
{{-- Chọn nhiều vai trò và phạm vi kho; mật khẩu trống khi sửa sẽ giữ mật khẩu cũ, Service bảo vệ admin cuối. --}}
@section('heading', $record ? 'Cập nhật tài khoản' : 'Tạo tài khoản nội bộ')
@section('content')
    <form method="post" class="edit-form" action="{{ $record ? route('users.update', $record) : route('users.store') }}">
        @csrf @if ($record)
            @method('PUT')
        @endif
        <div class="row g-4">
            <div class="col-lg-6">
                <x-field name="name" label="Họ tên" :value="$record?->name" required />
                <x-field name="email" label="Email" type="email" :value="$record?->email" required />
                <x-field name="phone" label="Số điện thoại" :value="$record?->phone" maxlength="10" required />
                <x-field name="password" label="Mật khẩu mới" type="password" :hint="$record ? 'Để trống nếu giữ nguyên.' : 'Ít nhất 10 ký tự.'"
                    autocomplete="new-password" />
                <x-field name="password_confirmation" label="Xác nhận mật khẩu" type="password"
                    autocomplete="new-password" />
                <x-field name="status" label="Trạng thái" :value="$record?->status ?? 'active'" :options="['active' => 'Hoạt động', 'inactive' => 'Đã khóa']" required />
            </div>
            <div class="col-lg-6">
                <fieldset class="choice-list">
                    <legend>Vai trò</legend>
                    <p class="form-text">Vận hành phụ trách điều phối, kho và CSKH. Admin kiêm kế toán. Cá nhân và cửa hàng tự đăng ký ở trang khách hàng.</p>
                    @foreach ($roles as $role)
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="role_ids[]"
                                value="{{ $role->id }}"
                                @checked(in_array($role->id, (array) old('role_ids', $record?->roles->modelKeys() ?? [])))><span>{{ $role->label }}</span></label>
                    @endforeach
                </fieldset>
                <fieldset class="choice-list">
                    <legend>Phạm vi kho</legend>
                    @foreach ($warehouses as $warehouse)
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="warehouse_ids[]"
                                value="{{ $warehouse->id }}"
                                @checked(in_array($warehouse->id, (array) old('warehouse_ids', $record?->warehouses->modelKeys() ?? [])))><span>{{ $warehouse->name }}</span></label>
                    @endforeach
                </fieldset>
                <x-field name="reason" label="Lý do tạo / thay đổi quyền" type="textarea" rows="3" required />
            </div>
        </div>
        <div class="form-actions"><button class="btn btn-primary"><i data-lucide="save"></i> Lưu tài khoản</button><a
                class="btn btn-link" href="{{ route('users.index') }}">Quay lại</a></div>
    </form>
@endsection
