{{-- Menu theo quyền chỉ phục vụ điều hướng; route/Policy vẫn kiểm quyền. --}}
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Không gian làm việc') | Gửi Việt</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="portal-body">
    <a class="skip-link" href="#portal-main">Đến nội dung chính</a>
    <header class="portal-topbar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-symbol"><i data-lucide="package-check"></i></span>Gửi Việt.</a>
        <div class="d-flex align-items-center gap-3">
            @php
                $notificationCount = auth()->user()->unreadNotifications()->count();
            @endphp
            <a class="btn icon-button notification-bell" href="{{ route('notifications.index') }}" aria-label="Thông báo, {{ $notificationCount }} chưa đọc" title="Thông báo"
                data-notification-counter="{{ route('notifications.count') }}">
                <i data-lucide="bell"></i>
                <span data-unread-count @if ($notificationCount === 0) hidden @endif>{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
            </a>
            <a href="{{ route('home') }}" class="small d-none d-sm-inline">Trang khách hàng</a>
            <span class="small d-none d-md-inline portal-account-name" title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</span>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-primary portal-logout" aria-label="Đăng xuất" title="Đăng xuất">
                    <i data-lucide="log-out" class="d-sm-none"></i><span class="d-none d-sm-inline">Đăng xuất</span></button></form>
            <button type="button" class="btn icon-button d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#portal-nav"
                aria-controls="portal-nav" aria-label="Mở điều hướng"><i data-lucide="menu"></i></button>
        </div>
    </header>
    <div class="portal-shell">
        <aside class="offcanvas-lg offcanvas-start portal-sidebar" id="portal-nav" tabindex="-1" aria-label="Điều hướng nghiệp vụ">
            <div class="offcanvas-header"><strong>Điều hướng</strong><button type="button" class="btn-close"
                    data-bs-dismiss="offcanvas" data-bs-target="#portal-nav" aria-label="Đóng"></button></div>
            <div class="offcanvas-body">
                @include('portal.partials.navigation')
            </div>
        </aside>
        <main class="portal-main" id="portal-main" tabindex="0" aria-label="Nội dung chính">
            @include('portal.partials.feedback')
            <div class="portal-heading">
                <h1>@yield('heading')</h1>
                @hasSection('actions')
                    <div class="portal-heading-actions">@yield('actions')</div>
                @endif
            </div>
            @yield('content')
        </main>
    </div>
    @include('portal.partials.confirmation')
</body>

</html>
