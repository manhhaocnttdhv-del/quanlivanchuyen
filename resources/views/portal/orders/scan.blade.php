@extends('layouts.portal')
@section('heading', 'Quét vận đơn')
@section('content')
    <section class="work-section order-scanner" data-order-scanner>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-primary" data-scan-start><i data-lucide="camera"></i> Bật camera</button>
            <button type="button" class="btn btn-outline-danger" data-scan-stop hidden><i data-lucide="square"></i> Dừng camera</button>
            <label class="btn btn-outline-primary mb-0" for="scan-image"><i data-lucide="image"></i> Chọn ảnh QR</label>
            <input id="scan-image" class="visually-hidden" type="file" accept="image/png,image/jpeg,image/webp">
        </div>
        <video class="scan-video" data-scan-video muted playsinline hidden aria-label="Camera quét QR"></video>
        <p class="small text-danger" data-scan-message role="status" aria-live="polite"></p>
        <form method="post" action="{{ route('orders.scan-resolve') }}" data-scan-form>
            @csrf
            <x-field name="code" label="Mã vận đơn hoặc nội dung QR" maxlength="2048" autocomplete="off" required />
            <button class="btn btn-primary mt-3"><i data-lucide="scan-search"></i> Mở vận đơn</button>
        </form>
    </section>
@endsection
