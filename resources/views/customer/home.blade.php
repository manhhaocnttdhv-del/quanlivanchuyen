@extends('layouts.customer')
@section('title', 'Gửi Việt | Giao hàng COD')

@section('content')
    @php
        $provinceOptions = collect($provinces)->map(fn($province) => $province['name'])->all();
        $serviceOptions = collect($services)->map(fn($service) => $service['label'])->all();
        $estimateActive =
            request('tool') === 'estimate' || $errors->hasAny(['origin', 'destination', 'weight', 'service']);
    @endphp
    <main>
        <section class="shipping-hero" aria-labelledby="hero-title">
            <img src="{{ asset('images/logistics-warehouse.jpg') }}" class="hero-image"
                alt="Các kiện hàng trên dây chuyền phân loại tại kho vận" width="2000" height="1333" fetchpriority="high">
            <div class="container hero-content">
                <p class="hero-category"><span></span> Đồng hành cùng cửa hàng của bạn</p>
                <h1 id="hero-title">Giao hàng COD</h1>
                <p class="hero-copy">Từ cửa hàng của bạn<br>đến tay người nhận.</p>
                <a href="{{ route('drafts.create') }}" class="btn btn-light">Bắt đầu gửi hàng <i
                        data-lucide="arrow-up-right"></i></a>
            </div>
            <div class="hero-caption"><i data-lucide="package"></i> Mỗi kiện hàng, một hành trình.</div>
        </section>

        <section class="tools-band" id="cong-cu" aria-label="Tra cứu và ước tính cước">
            <div class="container">
                <div class="row gx-lg-5">
                    <div class="col-lg-9">
                        <ul class="nav tool-tabs" role="tablist" aria-label="Công cụ gửi hàng">
                            <li class="nav-item" role="presentation"><button
                                    class="nav-link {{ $estimateActive ? '' : 'active' }}" id="track-tab"
                                    data-bs-toggle="tab" data-bs-target="#track-pane" type="button" role="tab"
                                    aria-controls="track-pane" aria-selected="{{ $estimateActive ? 'false' : 'true' }}"><i
                                        data-lucide="scan-search"></i> Tra cứu đơn hàng</button></li>
                            <li class="nav-item" role="presentation"><button
                                    class="nav-link {{ $estimateActive ? 'active' : '' }}" id="quote-tab"
                                    data-bs-toggle="tab" data-bs-target="#quote-pane" type="button" role="tab"
                                    aria-controls="quote-pane" aria-selected="{{ $estimateActive ? 'true' : 'false' }}"><i
                                        data-lucide="calculator"></i> Ước tính cước</button></li>
                        </ul>
                        <div class="tab-content tool-content">
                            <div class="tab-pane {{ $estimateActive ? '' : 'show active' }}" id="track-pane" role="tabpanel"
                                aria-labelledby="track-tab" tabindex="0">
                                <form id="tracking-form" method="post" action="{{ route('shipping.track') }}" novalidate>
                                    @csrf
                                    <div class="row g-3 align-items-start">
                                        <div class="col-md-5"><x-field name="tracking_code" label="Mã đơn hàng"
                                                placeholder="Nhập mã đơn hoặc mã nháp" maxlength="40" autocomplete="off"
                                                required /></div>
                                        <div class="col-md-4"><x-field name="phone_suffix" label="4 số cuối điện thoại nhận"
                                                placeholder="Ví dụ: 6789" inputmode="numeric" maxlength="4"
                                                autocomplete="off" required /></div>
                                        <div class="col-md-3 tool-submit"><button type="submit"
                                                class="btn btn-primary w-100"><i data-lucide="search"></i> Tra cứu</button>
                                        </div>
                                    </div>
                                </form>
                                <p class="tool-note"><i data-lucide="shield-check"></i> Thông tin người gửi và người nhận
                                    được giữ riêng tư.</p>
                                <div id="tracking-result" class="tracking-output {{ session('tracking') ? '' : 'd-none' }}"
                                    role="status" aria-live="polite">
                                    @if (session('tracking'))
                                        <strong>{{ session('tracking')['found'] ? 'Bản nháp · Chưa gửi vận chuyển' : 'Chưa tìm thấy đơn hàng' }}</strong>
                                        <p class="mb-0 mt-1">{{ session('tracking')['message'] }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="tab-pane {{ $estimateActive ? 'show active' : '' }}" id="quote-pane"
                                role="tabpanel" aria-labelledby="quote-tab" tabindex="0">
                                <form id="quote-form" method="post" action="{{ route('shipping.estimate') }}" novalidate>
                                    @csrf
                                    <div class="row g-3">
                                        <div class="col-md-6"><x-field name="origin" id="quote-origin" label="Nơi gửi"
                                                :options="$provinceOptions" placeholder="Chọn tỉnh/thành gửi" required /></div>
                                        <div class="col-md-6"><x-field name="destination" id="quote-destination"
                                                label="Nơi nhận" :options="$provinceOptions" placeholder="Chọn tỉnh/thành nhận"
                                                required /></div>
                                        <div class="col-md-4"><x-field name="weight" id="quote-weight"
                                                label="Khối lượng (gram)" type="number" :value="500" min="1"
                                                max="20000" step="1" required /></div>
                                        <div class="col-md-5"><x-field name="service" id="quote-service" label="Dịch vụ"
                                                :options="$serviceOptions" :value="request('service', 'standard')" required /></div>
                                        <div class="col-md-3 tool-submit"><button type="submit"
                                                class="btn btn-primary w-100"><i data-lucide="calculator"></i> Tính
                                                cước</button></div>
                                    </div>
                                </form>
                                <p class="tool-note">Biểu phí mẫu · Tối đa 20kg · Thời gian giao chỉ mang tính dự kiến.</p>
                                <div id="quote-result" class="quote-output {{ session('quote') ? '' : 'd-none' }}"
                                    role="status" aria-live="polite">
                                    @if (session('quote'))
                                        @include('customer.partials.quote-result', [
                                            'quote' => session('quote'),
                                        ])
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    <aside class="col-lg-3 quick-draft">
                        <span class="icon-tile"><i data-lucide="clipboard-list"></i></span>
                        <h2>Đơn hàng của bạn</h2>
                        <p>Tiếp tục chỉnh sửa các kiện hàng đã lưu.</p>
                        <a href="{{ route('drafts.index') }}">Mở danh sách <i data-lucide="arrow-right"></i></a>
                    </aside>
                </div>
            </div>
        </section>

        <section class="section-space services-band" id="dich-vu">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <p class="section-label">Dịch vụ vận chuyển</p>
                        <h2>Mỗi nhu cầu,<br> một cách gửi phù hợp.</h2>
                    </div>
                    <p>Gửi hàng thường ngày hay cần giao sớm?<br> Chọn dịch vụ phù hợp với kiện hàng của bạn.</p>
                </div>
                <div class="row g-4">
                    <div class="col-md-4">
                        <article class="service-item">
                            <div class="service-top"><span class="icon-tile"><i data-lucide="truck"></i></span><span
                                    class="service-tag">Thường ngày</span></div>
                            <h3>Giao tiêu chuẩn</h3>
                            <p>Lựa chọn cân bằng chi phí và thời gian cho các đơn hàng thông thường.</p><a
                                href="{{ route('home') }}?tool=estimate&service=standard#cong-cu">Xem cước tiêu chuẩn <i
                                    data-lucide="arrow-up-right"></i></a>
                        </article>
                    </div>
                    <div class="col-md-4">
                        <article class="service-item service-express">
                            <div class="service-top"><span class="icon-tile"><i data-lucide="zap"></i></span><span
                                    class="service-tag">Ưu tiên thời gian</span></div>
                            <h3>Giao nhanh</h3>
                            <p>Dành cho kiện cần giao sớm. Phụ phí dịch vụ được tách riêng khi tính cước.</p><a
                                href="{{ route('home') }}?tool=estimate&service=express#cong-cu">Xem cước giao nhanh <i
                                    data-lucide="arrow-up-right"></i></a>
                        </article>
                    </div>
                    <div class="col-md-4">
                        <article class="service-item service-cod">
                            <div class="service-top"><span class="icon-tile"><i data-lucide="banknote"></i></span><span
                                    class="service-tag">Cho cửa hàng</span></div>
                            <h3>Gửi hàng thu hộ</h3>
                            <p>Ghi rõ tiền hàng cần thu hộ, tách biệt với phí vận chuyển và người trả phí.</p><a
                                href="{{ route('drafts.create') }}">Chuẩn bị đơn COD <i
                                    data-lucide="arrow-up-right"></i></a>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section class="process-band section-space">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <p class="section-label">Chuẩn bị gửi hàng</p>
                        <h2>Sẵn sàng trong 3 bước.</h2>
                    </div><a href="{{ route('drafts.create') }}" class="btn btn-outline-primary">Tạo đơn hàng <i
                            data-lucide="arrow-right"></i></a>
                </div>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="process-step"><span>01</span>
                            <div>
                                <h3>Điền thông tin</h3>
                                <p>Người gửi, người nhận, địa chỉ và nội dung kiện hàng.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="process-step"><span>02</span>
                            <div>
                                <h3>Kiểm tra cước & COD</h3>
                                <p>Xem cước tham khảo, số tiền thu hộ và người trả phí.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="process-step"><span>03</span>
                            <div>
                                <h3>Lưu bản nháp</h3>
                                <p>Lưu để kiểm tra lại. Bản nháp chưa được gửi vận chuyển.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section-space" id="bang-cuoc">
            <div class="container">
                <div class="section-heading">
                    <div>
                        <p class="section-label">Chi phí rõ ràng</p>
                        <h2>Bảng cước tham khảo</h2>
                    </div><a href="{{ route('home') }}?tool=estimate#cong-cu" class="text-action">Tính cho kiện hàng của
                        bạn <i data-lucide="arrow-up-right"></i></a>
                </div>
                <div class="table-responsive price-table-wrap">
                    <table class="table price-table align-middle">
                        <caption>Biểu phí mẫu. Các mức giá dưới đây áp dụng cho dịch vụ tiêu chuẩn.</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tuyến vận chuyển</th>
                                <th scope="col">Đến 500g</th>
                                <th scope="col">Mỗi 500g tiếp theo</th>
                                <th scope="col">Thời gian dự kiến</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rates as $rate)
                                <tr>
                                    <th scope="row"><i data-lucide="route"></i> {{ $rate['label'] }}</th>
                                    <td class="price-value">{{ number_format($rate['base'], 0, ',', '.') }}đ</td>
                                    <td>+{{ number_format($rate['step'], 0, ',', '.') }}đ</td>
                                    <td>{{ $rate['days'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="small text-secondary mt-3 mb-0">Giao nhanh: cộng
                    {{ number_format($services['express']['surcharge'], 0, ',', '.') }}đ/kiện. Khối lượng vượt bậc được làm
                    tròn lên mỗi 500g. Chưa gồm phụ phí phát sinh.</p>
            </div>
        </section>

        <section class="faq-band section-space" id="ho-tro">
            <div class="container">
                <div class="row gx-4 gx-lg-5 gy-4">
                    <div class="col-lg-4">
                        <p class="section-label">Hỗ trợ gửi hàng</p>
                        <h2>Bạn cần biết thêm?</h2>
                        <p class="text-secondary mt-3">Một vài giải đáp trước khi chuẩn bị kiện hàng.</p>
                    </div>
                    <div class="col-lg-8">
                        <div class="accordion accordion-flush" id="faq-list">
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#faq-one" aria-expanded="true"
                                        aria-controls="faq-one">Lưu bản nháp xong là đã gửi hàng chưa?</button></h3>
                                <div id="faq-one" class="accordion-collapse collapse show" data-bs-parent="#faq-list">
                                    <div class="accordion-body">Chưa. Bản nháp giúp kiểm tra thông tin, chưa phát sinh phí
                                        hay yêu cầu lấy hàng. Đơn hiện lưu theo phiên trình duyệt và có thể mất khi phiên
                                        hết hạn.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#faq-two" aria-expanded="false"
                                        aria-controls="faq-two">Cước tham khảo có phải số tiền cuối cùng?</button></h3>
                                <div id="faq-two" class="accordion-collapse collapse" data-bs-parent="#faq-list">
                                    <div class="accordion-body">Chưa. Cước hiện tính theo biểu phí mẫu, tỉnh/thành và cân
                                        nặng đã nhập. Cước chính thức cần xác nhận địa chỉ, kích thước, cân nặng thực tế và
                                        các phụ phí áp dụng.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#faq-three" aria-expanded="false"
                                        aria-controls="faq-three">Tiền COD và phí vận chuyển khác nhau thế nào?</button>
                                </h3>
                                <div id="faq-three" class="accordion-collapse collapse" data-bs-parent="#faq-list">
                                    <div class="accordion-body">COD là tiền hàng cần thu hộ cho người gửi. Phí vận chuyển
                                        là chi phí giao kiện hàng. Bạn chọn người trả phí riêng khi tạo bản nháp; hai khoản
                                        này không được gộp thành tiền hàng.</div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h3 class="accordion-header"><button class="accordion-button collapsed" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#faq-four" aria-expanded="false"
                                        aria-controls="faq-four">Vì sao không tìm thấy đơn của tôi?</button></h3>
                                <div id="faq-four" class="accordion-collapse collapse" data-bs-parent="#faq-list">
                                    <div class="accordion-body">Kiểm tra lại mã và 4 số cuối điện thoại người nhận. Hiện
                                        chỉ tra cứu được bản nháp trong cùng phiên trình duyệt; trang chưa kết nối với đơn
                                        vận chuyển thực tế hoặc dữ liệu hãng giao hàng khác.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
@endsection
