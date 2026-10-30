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
    <header class="portal-topbar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-symbol"><i
                    data-lucide="package-check"></i></span>Gửi Việt.</a>
        <div class="d-flex align-items-center gap-3">
            <a href="{{ route('home') }}" class="small d-none d-sm-inline">Trang khách hàng</a>
            <span class="small d-none d-md-inline">{{ auth()->user()->name }}</span>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-primary">Đăng
                    xuất</button></form>
            <button class="btn icon-button d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#portal-nav"
                aria-label="Mở điều hướng"><i data-lucide="menu"></i></button>
        </div>
    </header>
    <div class="portal-shell">
        <aside class="offcanvas-lg offcanvas-start portal-sidebar" id="portal-nav" tabindex="-1"
            aria-label="Điều hướng nghiệp vụ">
            <div class="offcanvas-header"><strong>Điều hướng</strong><button class="btn-close"
                    data-bs-dismiss="offcanvas" data-bs-target="#portal-nav" aria-label="Đóng"></button></div>
            <div class="offcanvas-body">
                <nav class="portal-nav">
                    <p class="nav-group-label">Không gian làm việc</p>
                    <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i
                            data-lucide="package"></i> Tổng quan</a>
                    <a class="{{ request()->routeIs('profile.*') ? 'active' : '' }}"
                        href="{{ route('profile.edit') }}"><i data-lucide="info"></i> Hồ sơ cá nhân</a>
                    @if (auth()->user()->shop)
                        <a class="{{ request()->routeIs('shop.*', 'pickups.*') ? 'active' : '' }}"
                            href="{{ route('shop.profile') }}"><i data-lucide="map-pin-house"></i> Cửa hàng & địa
                            chỉ</a>
                    @endif
                    @can('viewAny', \App\Models\Order::class)
                        <a class="{{ request()->routeIs('orders.*') ? 'active' : '' }}"
                            href="{{ route('orders.index') }}"><i data-lucide="clipboard-list"></i> Vận đơn</a>
                    @endcan
                    @if (auth()->user()->hasPermission('logistics.dispatch') ||
                            auth()->user()->hasPermission('logistics.warehouse') ||
                            auth()->user()->hasRole('shipper'))
                        <a class="{{ request()->routeIs('logistics.index', 'logistics.show', 'logistics.order', 'logistics.handover', 'delivery.mine') ? 'active' : '' }}"
                            href="{{ route(auth()->user()->hasRole('shipper') ? 'delivery.mine' : 'logistics.index') }}"><i
                                data-lucide="truck"></i>
                            {{ auth()->user()->hasRole('shipper') ? 'Nhiệm vụ của tôi' : 'Điều phối & bàn giao' }}</a>
                    @endif
                    @if (auth()->user()->hasRole('admin') ||
                            auth()->user()->hasRole('shipper') ||
                            auth()->user()->hasPermission('delivery.collections.view') ||
                            auth()->user()->shop)
                        <a class="{{ request()->routeIs('delivery.collections') ? 'active' : '' }}"
                            href="{{ route('delivery.collections') }}"><i data-lucide="banknote"></i> Sổ thu giao
                            hàng</a>
                    @endif
                    @if (auth()->user()->hasPermission('logistics.warehouse') || auth()->user()->hasPermission('logistics.dispatch'))
                        <a class="{{ request()->routeIs('logistics.inventory') ? 'active' : '' }}"
                            href="{{ route('logistics.inventory') }}"><i data-lucide="package-check"></i> Tồn kho & sai
                            lệch</a>
                        <a class="{{ request()->routeIs('stocktakes.*') ? 'active' : '' }}"
                            href="{{ route('stocktakes.index') }}"><i data-lucide="clipboard-list"></i> Kiểm kê kho</a>
                    @endif
                    @can('users.manage')
                        <p class="nav-group-label mt-4">Quản lý</p>
                    @endcan
                    @if (app(\App\Policies\FinancePolicy::class)->any(auth()->user()))
                        <a class="{{ request()->routeIs('finance.*') ? 'active' : '' }}" href="{{ route('finance.index') }}"><i data-lucide="wallet"></i> COD & tài chính</a>
                    @endif
                    @can('viewAny', \App\Models\Incident::class)
                        <a class="{{ request()->routeIs('incidents.*') ? 'active' : '' }}"
                            href="{{ route('incidents.index') }}"><i data-lucide="triangle-alert"></i> Sự cố vận chuyển</a>
                    @endcan
                    @can('viewAny', \App\Models\Complaint::class)
                        <a class="{{ request()->routeIs('complaints.*') ? 'active' : '' }}"
                            href="{{ route('complaints.index') }}"><i data-lucide="messages-square"></i> Khiếu nại</a>
                    @endcan
                    @can('users.manage')
                        <a class="{{ request()->routeIs('users.*') ? 'active' : '' }}"
                            href="{{ route('users.index') }}"><i data-lucide="shield-check"></i> Tài khoản & phân quyền</a>
                    @endcan
                    @can('any-shop-review')
                        <a class="{{ request()->routeIs('shops.*') ? 'active' : '' }}"
                            href="{{ route('shops.index') }}"><i data-lucide="banknote"></i> Duyệt shop & ngân hàng</a>
                    @endcan
                    @can('catalog.manage')
                        <a class="{{ request()->routeIs('catalog.*', 'areas.*', 'warehouses.*') ? 'active' : '' }}"
                            href="{{ route('catalog.index') }}"><i data-lucide="map-pin"></i> Khu vực & kho</a>
                        <a class="{{ request()->routeIs('fees.*') ? 'active' : '' }}" href="{{ route('fees.index') }}"><i
                                data-lucide="receipt-text"></i> Phiên bản biểu phí</a>
                    @endcan
                    @can('audit.view')
                        <a class="{{ request()->routeIs('audit.*') ? 'active' : '' }}"
                            href="{{ route('audit.index') }}"><i data-lucide="scan-search"></i> Nhật ký thay đổi</a>
                    @endcan
                    <div class="portal-user">
                        <strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->roles->pluck('label')->join(', ') }}</small><small>{{ auth()->user()->warehouses->pluck('name')->join(', ') ?: 'Chưa gán phạm vi kho' }}</small>
                    </div>
                </nav>
            </div>
        </aside>
        <main class="portal-main" tabindex="0" aria-label="Nội dung chính">
            @include('portal.partials.feedback')
            <div class="portal-heading">
                <h1>@yield('heading')</h1>@yield('actions')
            </div>
            @yield('content')
        </main>
    </div>
</body>

</html>
