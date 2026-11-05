@extends('layouts.customer')
@section('title', 'Đăng nhập | Gửi Việt')
@section('content')
    <main class="auth-band">
        <div class="container">
            <div class="auth-form">
                <p class="section-label">Tài khoản Gửi Việt</p>
                <h1>Đăng nhập</h1>
                <p class="text-secondary">Quản lý thông tin gửi hàng và vận đơn của bạn.</p>
                <form method="post" action="{{ route('login.store') }}" novalidate>@csrf
                    <x-field name="identity" label="Email hoặc số điện thoại" autocomplete="username" required />
                    <x-field name="password" type="password" label="Mật khẩu" autocomplete="current-password" required />
                    <button class="btn btn-primary w-100 mt-4">Đăng nhập</button>
                </form>
                <p class="mt-4 mb-0 small">Chưa có tài khoản? <a href="{{ route('register') }}">Đăng ký gửi hàng</a></p>
            </div>
        </div>
    </main>
@endsection
