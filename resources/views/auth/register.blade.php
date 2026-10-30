@extends('layouts.customer')
{{-- Đăng ký tạo tài khoản kèm shop chờ duyệt; shop chưa được gửi đơn trước khi hồ sơ được duyệt. --}}
@section('title', 'Đăng ký cửa hàng | Gửi Việt')
@section('content')
    <main class="auth-band">
        <div class="container">
            <div class="auth-form auth-wide">
                <h1>Đăng ký cửa hàng</h1>
                <p class="text-secondary">Cửa hàng được xét duyệt trước khi gửi vận đơn.</p>
                <form method="post" action="{{ route('register.store') }}" novalidate>@csrf<div class="row g-3">
                        <div class="col-md-6"><x-field name="name" label="Họ tên chủ shop" maxlength="100" required /></div>
                        <div class="col-md-6"><x-field name="shop_name" label="Tên cửa hàng" maxlength="150" required />
                        </div>
                        <div class="col-md-6"><x-field name="email" label="Email" type="email" autocomplete="email"
                                required /></div>
                        <div class="col-md-6"><x-field name="phone" label="Số điện thoại" type="tel" maxlength="10"
                                required /></div>
                        <div class="col-md-6"><x-field name="password" label="Mật khẩu" type="password"
                                hint="Ít nhất 10 ký tự." autocomplete="new-password" required /></div>
                        <div class="col-md-6"><x-field name="password_confirmation" label="Xác nhận mật khẩu"
                                type="password" autocomplete="new-password" required /></div>
                        <div class="col-md-5"><x-field name="area_id" label="Tỉnh/thành" :options="$areas"
                                placeholder="Chọn tỉnh/thành" required /></div>
                        <div class="col-md-7"><x-field name="address" label="Địa chỉ cửa hàng" required /></div>
                    </div><button class="btn btn-primary mt-4">Đăng ký cửa hàng</button><a class="btn btn-link mt-4"
                        href="{{ route('login') }}">Đã có tài khoản</a></form>
            </div>
        </div>
    </main>
@endsection
