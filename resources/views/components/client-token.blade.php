{{-- Giữ UUID hợp lệ khi nhập lại form; token sai kiểu/định dạng được tạo mới để form phục hồi sau validation. --}}
@props(['value' => null])
@php
    $candidate = old('client_token', $value);
    $token = is_string($candidate) && \Illuminate\Support\Str::isUuid($candidate)
        ? $candidate : (string) \Illuminate\Support\Str::uuid();
@endphp
<input type="hidden" name="client_token" value="{{ $token }}">
