@extends('layouts.portal')
@section('heading', $record ? 'Cập nhật địa chỉ lấy hàng' : 'Thêm địa chỉ lấy hàng')
@section('content')
    <form class="edit-form narrow-form" method="post"
        action="{{ $record ? route('pickups.update', $record) : route('pickups.store') }}">@csrf @if ($record)
            @method('PUT')
        @endif
        <x-field name="contact_name" label="Người liên hệ" :value="$record?->contact_name" required />
        <x-field name="phone" label="Số điện thoại" :value="$record?->phone" maxlength="10" required />
        <x-field name="area_id" label="Tỉnh/thành" :options="$areas" :value="$record?->area_id" required />
        <x-field name="address" label="Địa chỉ chi tiết" :value="$record?->address" required />
        <input type="hidden" name="is_active" value="0"><label class="form-check mt-3"><input class="form-check-input"
                name="is_active" type="checkbox" value="1" @checked(old('is_active', $record?->is_active ?? true))> Đang sử dụng</label>
        <input type="hidden" name="is_default" value="0"><label class="form-check mt-2"><input
                class="form-check-input" name="is_default" type="checkbox" value="1" @checked(old('is_default', $record?->is_default ?? false))> Địa
            chỉ mặc định</label>
        <div class="form-actions"><button class="btn btn-primary"><i data-lucide="save"></i> Lưu địa chỉ</button><a
                class="btn btn-link" href="{{ route('shop.profile') }}">Quay lại</a></div>
    </form>
@endsection
