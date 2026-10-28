<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Gửi Việt: chuẩn bị đơn hàng COD, tra cứu và xem cước vận chuyển tham khảo.">
    <title>@yield('title', 'Gửi Việt | Giao hàng COD')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <a class="skip-link" href="#main-content">Đến nội dung chính</a>
    <nav class="navbar navbar-expand-lg site-nav" aria-label="Điều hướng chính">
        <div class="container">
            <a class="navbar-brand brand" href="{{ route('home') }}" aria-label="Gửi Việt - Trang chủ">
                <span class="brand-symbol"><i data-lucide="package-check"></i></span>Gửi Việt<span
                    class="brand-dot">.</span>
            </a>
            <button class="navbar-toggler icon-button" type="button" data-bs-toggle="collapse"
                data-bs-target="#customer-nav" aria-controls="customer-nav" aria-expanded="false" aria-label="Mở menu">
                <i data-lucide="menu"></i>
            </button>
            <div class="collapse navbar-collapse" id="customer-nav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="{{ route('home') }}#dich-vu">Dịch vụ</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('home') }}#bang-cuoc">Bảng cước</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('home') }}#ho-tro">Hỗ trợ</a></li>
                    <li class="nav-item nav-account"><a
                            class="nav-link {{ request()->routeIs('drafts.index') ? 'active' : '' }}"
                            href="{{ route('drafts.index') }}"
                            @if (request()->routeIs('drafts.index')) aria-current="page" @endif><i
                                data-lucide="clipboard-list"></i> Đơn của tôi</a></li>
                    <li class="nav-item"><a class="btn btn-primary" href="{{ route('drafts.create') }}"><i
                                data-lucide="plus"></i> Tạo đơn hàng</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div id="main-content" tabindex="-1">
        @if (session('status'))
            <div class="container pt-4">
                <div class="alert alert-success d-flex gap-2" role="status"><i
                        data-lucide="circle-check"></i><span>{{ session('status') }}</span></div>
            </div>
        @endif
        @if ($errors->any())
            <div class="container pt-4">
                <div class="alert alert-danger" role="alert" tabindex="-1" data-validation-summary>
                    <strong>Vui lòng kiểm tra lại thông tin.</strong>
                    <ul class="mb-0 mt-2">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
        @yield('content')
    </div>
    <footer class="site-footer">
        <div class="container">
            <div class="row g-4 footer-main">
                <div class="col-lg-5">
                    <a class="brand" href="{{ route('home') }}"><span class="brand-symbol"><i
                                data-lucide="package-check"></i></span>Gửi Việt<span class="brand-dot">.</span></a>
                    <p class="mt-3 mb-0">Kết nối từng kiện hàng,<br>gửi trọn sự an tâm.</p>
                </div>
                <div class="col-6 col-lg-3">
                    <h2>Gửi hàng</h2>
                    <a href="{{ route('drafts.create') }}">Tạo đơn hàng</a>
                    <a href="{{ route('drafts.index') }}">Đơn của tôi</a>
                    <a href="{{ route('home') }}?tool=estimate#cong-cu">Ước tính cước</a>
                </div>
                <div class="col-6 col-lg-4">
                    <h2>Thông tin</h2>
                    <a href="{{ route('home') }}#dich-vu">Dịch vụ vận chuyển</a>
                    <a href="{{ route('home') }}#bang-cuoc">Bảng cước tham khảo</a>
                    <a href="{{ route('home') }}#ho-tro">Câu hỏi thường gặp</a>
                </div>
            </div>
            <div class="footer-bottom"><span>© {{ date('Y') }} Gửi Việt</span><span>Phiên bản đồ án · Chưa tiếp
                    nhận vận chuyển thực tế</span></div>
        </div>
    </footer>
</body>

</html>
