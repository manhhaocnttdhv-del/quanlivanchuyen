@extends('layouts.customer')
@section('title', 'Đơn của tôi | Gửi Việt')

@section('content')
<main>
    <header class="page-heading">
        <div class="container">
            <p class="section-label mb-2">Khu vực cá nhân</p>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div><h1>Đơn của tôi</h1><p>Các bản nháp được lưu trong phiên trình duyệt hiện tại.</p></div>
                <a href="{{ route('drafts.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tạo đơn hàng</a>
            </div>
        </div>
    </header>
    <section class="drafts-band">
        <div class="container">
            <form class="search-form" method="get" action="{{ route('drafts.index') }}">
                <x-field name="q" label="Tìm bản nháp" :value="$search" placeholder="Tìm theo mã, tên hoặc số điện thoại nhận" maxlength="100" autocomplete="off" />
                <button class="btn btn-outline-primary" type="submit"><i data-lucide="search"></i> Tìm</button>
                @if($search !== '')<a class="btn btn-link" href="{{ route('drafts.index') }}">Xóa lọc</a>@endif
            </form>
            <p class="small text-secondary mb-3">{{ $drafts->count() }} bản nháp · Trạng thái hiện tại: chưa gửi vận chuyển</p>
            @if($drafts->isEmpty())
                <div class="empty-state">
                    <span class="icon-tile"><i data-lucide="file-plus-2"></i></span>
                    <h2>{{ $search === '' ? 'Chưa có bản nháp nào' : 'Không tìm thấy bản nháp phù hợp' }}</h2>
                    <p>{{ $search === '' ? 'Tạo một bản nháp để nhập thông tin người gửi, người nhận và xem cước tham khảo.' : 'Thử lại bằng mã đơn, tên người nhận hoặc số điện thoại khác.' }}</p>
                    @if($search === '')<a href="{{ route('drafts.create') }}" class="btn btn-primary">Tạo bản nháp đầu tiên</a>@endif
                </div>
            @else
                <div class="draft-list">
                    @foreach($drafts as $draft)
                        <article class="draft-item">
                            <div class="draft-main"><div class="draft-code">{{ $draft['code'] }}</div><h2>{{ $draft['recipient_name'] }}</h2><p>{{ $draft['recipient_phone'] }} · {{ $provinces[$draft['destination']]['name'] ?? $draft['destination'] }}</p></div>
                            <div><p class="mb-1">Hàng hóa</p><h2>{{ $draft['goods'] }}</h2><p>{{ number_format($draft['weight'], 0, ',', '.') }}g · {{ $draft['service'] === 'express' ? 'Giao nhanh' : 'Tiêu chuẩn' }}</p></div>
                            <div><p class="mb-1">Cập nhật</p><h2>{{ $draft['updated_at'] }}</h2><p><span class="status-badge">Bản nháp</span></p></div>
                            <div class="draft-actions"><a class="btn btn-outline-primary" href="{{ route('drafts.edit', $draft['id']) }}"><i data-lucide="pencil"></i> Sửa</a><button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-modal" data-delete-url="{{ route('drafts.destroy', $draft['id']) }}" data-code="{{ $draft['code'] }}" aria-label="Xóa {{ $draft['code'] }}"><i data-lucide="trash-2"></i></button></div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</main>
<div class="modal fade" id="delete-modal" tabindex="-1" aria-labelledby="delete-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title" id="delete-modal-title">Xóa bản nháp?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div><div class="modal-body"><p class="mb-0">Bạn sắp xóa <strong id="delete-code"></strong>. Thao tác này không thể khôi phục trong phiên hiện tại.</p></div><div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Hủy</button><form id="delete-form" method="post">@csrf @method('DELETE')<button type="submit" class="btn btn-danger"><i data-lucide="trash-2"></i> Xóa bản nháp</button></form></div></div></div>
</div>
@endsection
