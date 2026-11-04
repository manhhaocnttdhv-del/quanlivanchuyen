@extends('layouts.portal')
@section('heading', $record ? 'Cập nhật khu vực' : 'Thêm khu vực')
@section('content')
    <form class="edit-form narrow-form" method="post"
        action="{{ $record ? route('areas.update', $record) : route('areas.store') }}">@csrf @if ($record)
            @method('PUT')
        @endif
        <x-field name="code" label="Mã khu vực" :value="$record?->code" required /><x-field name="name" label="Tên khu vực"
            :value="$record?->name" required />
        <x-field name="region" label="Miền" :value="$record?->region" :options="['north' => 'Bắc', 'central' => 'Trung', 'south' => 'Nam']" required />
        <input type="hidden" name="is_active" value="0"><label class="form-check mt-3"><input name="is_active"
                type="checkbox" class="form-check-input" value="1" @checked(old('is_active', $record?->is_active ?? true))> Đang phục vụ</label>
        <div class="form-actions"><button class="btn btn-primary">Lưu khu vực</button><a class="btn btn-link"
                href="{{ route('catalog.index') }}">Quay lại</a></div>
    </form>
@endsection
