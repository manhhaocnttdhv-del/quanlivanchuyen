@extends('layouts.portal')
{{-- Form tạo/sửa dùng POST/PUT; khu vực gợi ý đang hoạt động, server kiểm tra lại trước lưu. --}}
@section('heading', $record ? 'Cập nhật kho' : 'Thêm kho')
@section('content')
    <form class="edit-form narrow-form" method="post"
        action="{{ $record ? route('warehouses.update', $record) : route('warehouses.store') }}">@csrf @if ($record)
            @method('PUT')
        @endif
        <x-field name="code" label="Mã kho" :value="$record?->code" required /><x-field name="name" label="Tên kho"
            :value="$record?->name" required />
        <x-field name="area_id" label="Khu vực" :value="$record?->area_id" :options="$areas" required /><x-field name="address"
            label="Địa chỉ kho" :value="$record?->address" required />
        <input type="hidden" name="is_active" value="0"><label class="form-check mt-3"><input name="is_active"
                type="checkbox" class="form-check-input" value="1" @checked(old('is_active', $record?->is_active ?? true))> Đang hoạt động</label>
        <div class="form-actions"><button class="btn btn-primary">Lưu kho</button><a class="btn btn-link"
                href="{{ route('catalog.index') }}">Quay lại</a></div>
    </form>
@endsection
