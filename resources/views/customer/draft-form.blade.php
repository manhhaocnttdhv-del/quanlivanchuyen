@extends('layouts.customer')
@section('title', ($draft ? 'Sửa bản nháp' : 'Tạo đơn hàng').' | Gửi Việt')

@section('content')
@php
    $provinceOptions = collect($provinces)->map(fn ($province) => $province['name'])->all();
    $serviceOptions = collect($services)->map(fn ($service) => $service['label'])->all();
@endphp
<main>
    <header class="page-heading">
        <div class="container">
            <a href="{{ route('drafts.index') }}" class="back-link"><i data-lucide="arrow-left"></i> Đơn của tôi</a>
            <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                <h1>{{ $draft ? 'Chỉnh sửa đơn hàng' : 'Tạo đơn hàng' }}</h1>
                <span class="status-badge">Bản nháp</span>
            </div>
            <p>Chuẩn bị thông tin gửi hàng. Bản nháp chưa được tiếp nhận vận chuyển.</p>
        </div>
    </header>
    <section class="form-band">
        <div class="container">
            <form id="draft-form" method="post" action="{{ $draft ? route('drafts.update', $draft['id']) : route('drafts.store') }}" data-estimate-url="{{ route('shipping.estimate') }}" novalidate>
                @csrf
                @if($draft) @method('PUT') @endif
                <div class="row gx-lg-5">
                    <div class="col-lg-8">
                        <section class="form-section">
                            <h2><i data-lucide="map-pin-house"></i> Người gửi</h2>
                            <div class="row g-3">
                                <div class="col-md-6"><x-field name="sender_name" label="Họ tên" :value="$draft['sender_name'] ?? ''" autocomplete="section-sender name" maxlength="100" required /></div>
                                <div class="col-md-6"><x-field name="sender_phone" label="Số điện thoại" type="tel" :value="$draft['sender_phone'] ?? ''" autocomplete="section-sender tel-national" maxlength="10" inputmode="numeric" required /></div>
                                <div class="col-md-5"><x-field name="origin" label="Tỉnh/thành" :options="$provinceOptions" :value="$draft['origin'] ?? ''" placeholder="Chọn tỉnh/thành gửi" required /></div>
                                <div class="col-md-7"><x-field name="sender_address" label="Địa chỉ lấy hàng" :value="$draft['sender_address'] ?? ''" placeholder="Số nhà, đường, phường/xã" maxlength="255" required /></div>
                            </div>
                        </section>
                        <section class="form-section">
                            <h2><i data-lucide="map-pin"></i> Người nhận</h2>
                            <div class="row g-3">
                                <div class="col-md-6"><x-field name="recipient_name" label="Họ tên" :value="$draft['recipient_name'] ?? ''" autocomplete="section-recipient name" maxlength="100" required /></div>
                                <div class="col-md-6"><x-field name="recipient_phone" label="Số điện thoại" type="tel" :value="$draft['recipient_phone'] ?? ''" autocomplete="section-recipient tel-national" maxlength="10" inputmode="numeric" required /></div>
                                <div class="col-md-5"><x-field name="destination" label="Tỉnh/thành" :options="$provinceOptions" :value="$draft['destination'] ?? ''" placeholder="Chọn tỉnh/thành nhận" required /></div>
                                <div class="col-md-7"><x-field name="recipient_address" label="Địa chỉ giao hàng" :value="$draft['recipient_address'] ?? ''" placeholder="Số nhà, đường, phường/xã" maxlength="255" required /></div>
                            </div>
                        </section>
                        <section class="form-section">
                            <h2><i data-lucide="package"></i> Hàng hóa & vận chuyển</h2>
                            <div class="row g-3">
                                <div class="col-md-7"><x-field name="goods" label="Tên hàng hóa" :value="$draft['goods'] ?? ''" placeholder="Ví dụ: 2 áo sơ mi" maxlength="200" required /></div>
                                <div class="col-md-5"><x-field name="weight" label="Khối lượng (gram)" type="number" :value="$draft['weight'] ?? 500" min="1" max="20000" step="1" hint="Tối đa 20.000g (20kg)." required /></div>
                                <div class="col-md-6"><x-field name="service" label="Dịch vụ" :options="$serviceOptions" :value="$draft['service'] ?? 'standard'" required /></div>
                                <div class="col-md-6"><x-field name="cod" label="Tiền thu hộ COD (đồng)" type="number" :value="$draft['cod'] ?? 0" min="0" max="20000000" step="1" hint="Nhập 0 nếu không thu hộ. Tối đa 20 triệu đồng." required /></div>
                                <div class="col-md-6"><x-field name="fee_payer" label="Người trả phí vận chuyển" :options="['sender' => 'Người gửi', 'recipient' => 'Người nhận']" :value="$draft['fee_payer'] ?? 'sender'" required /></div>
                                <div class="col-12"><x-field name="note" label="Ghi chú giao hàng (không bắt buộc)" type="textarea" :value="$draft['note'] ?? ''" rows="3" maxlength="500" placeholder="Ví dụ: Gọi trước khi giao" /></div>
                            </div>
                        </section>
                    </div>
                    <aside class="col-lg-4">
                        <div class="draft-summary">
                            <div class="summary-heading"><i data-lucide="receipt-text"></i><h2>Kiểm tra đơn</h2></div>
                            @if($draft)<div class="draft-code mb-3">{{ $draft['code'] }}</div>@endif
                            <p class="small text-secondary">Cước tham khảo theo tỉnh/thành, khối lượng và dịch vụ bạn chọn.</p>
                            <button type="button" class="btn btn-outline-primary w-100" id="draft-estimate"><i data-lucide="calculator"></i> Tính cước</button>
                            <div id="draft-quote" class="quote-output mt-3" role="status" aria-live="polite">
                                @if($quote && !$errors->any())
                                    @include('customer.partials.quote-result', ['quote' => $quote])
                                @else
                                    <p class="small text-secondary mb-0">Chọn nơi gửi, nơi nhận và khối lượng để tính cước.</p>
                                @endif
                            </div>
                            <p class="session-note"><i data-lucide="info"></i> Bản nháp chỉ lưu trong phiên trình duyệt hiện tại và có thể mất khi phiên hết hạn. Chưa phát sinh phí hay giao dịch COD.</p>
                            <button type="submit" class="btn btn-primary w-100"><i data-lucide="save"></i> {{ $draft ? 'Lưu thay đổi' : 'Lưu bản nháp' }}</button>
                            <a href="{{ route('drafts.index') }}" class="btn btn-link w-100 mt-1">Quay lại danh sách</a>
                        </div>
                    </aside>
                </div>
            </form>
        </div>
    </section>
</main>
@endsection
