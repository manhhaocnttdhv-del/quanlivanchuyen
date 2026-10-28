@extends('layouts.portal')
@section('heading', 'Hồ sơ cá nhân')
@section('content')
    <div class="row g-5">
        <section class="col-lg-6">
            <h2 class="h5 mb-4">Thông tin liên hệ</h2>
            <form method="post" action="{{ route('profile.update') }}">@csrf @method('PUT')
                <x-field name="name" label="Họ tên" :value="$user->name" required />
                <x-field name="email" label="Email" type="email" :value="$user->email" required />
                <x-field name="phone" label="Điện thoại" :value="$user->phone" required />
                <button class="btn btn-primary mt-4"><i data-lucide="save"></i> Lưu hồ sơ</button>
            </form>
        </section>
        <section class="col-lg-6">
            <h2 class="h5 mb-4">Đổi mật khẩu</h2>
            <form method="post" action="{{ route('profile.password') }}">@csrf @method('PUT')
                <x-field name="current_password" label="Mật khẩu hiện tại" type="password" autocomplete="current-password"
                    required />
                <x-field name="password" label="Mật khẩu mới" type="password" hint="Ít nhất 10 ký tự."
                    autocomplete="new-password" required />
                <x-field name="password_confirmation" label="Xác nhận mật khẩu" type="password" autocomplete="new-password"
                    required />
                <button class="btn btn-outline-primary mt-4">Đổi mật khẩu</button>
            </form>
        </section>
    </div>
@endsection
