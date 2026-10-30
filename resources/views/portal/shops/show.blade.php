@extends('layouts.portal')
{{-- Xét shop và xác minh ngân hàng là hai quyền độc lập; chỉ phiên bản ngân hàng hiện hành được xác minh. --}}
@section('heading', $shop->name)
@section('content')
    <div class="row g-4 g-lg-5">
        <section class="col-lg-6">
            <h2 class="h5 mb-3">Hồ sơ đăng ký</h2>
            <dl class="detail-grid">
                <dt>Chủ shop</dt>
                <dd>{{ $shop->user->name }}</dd>
                <dt>Liên hệ</dt>
                <dd>{{ $shop->user->email }}<br>{{ $shop->user->phone }}</dd>
                <dt>Địa chỉ</dt>
                <dd>{{ $shop->address }}, {{ $shop->area->name }}</dd>
                <dt>Trạng thái</dt>
                <dd>{{ \App\Models\Shop::STATUSES[$shop->status] }}</dd>
                <dt>Kết quả gần nhất</dt>
                <dd>{{ $shop->review_reason ?: 'Chưa có' }}</dd>
            </dl>
            @can('review', $shop)
                <form method="post" action="{{ route('shops.review', $shop) }}">@csrf
                    <x-field name="status" label="Kết quả xét duyệt" :options="['approved' => 'Phê duyệt', 'rejected' => 'Từ chối', 'suspended' => 'Tạm ngừng']" required />
                    <x-field name="reason" label="Lý do / căn cứ xét duyệt" type="textarea" rows="3" required />
                    <button class="btn btn-primary mt-3">Lưu kết quả duyệt</button>
                </form>
            @endcan
        </section>
        <section class="col-lg-6">
            <h2 class="h5 mb-3">Phiên bản ngân hàng</h2>
            @forelse($shop->bankAccounts->sortByDesc('version') as $bank)
                <div class="bank-version"><strong>{{ $bank->bank_name }} · {{ $bank->masked_number }}</strong>
                    <p class="small mb-2">{{ $bank->holder_name }} · Phiên bản {{ $bank->version }} ·
                        {{ $bank->verified_at ? 'Đã xác minh' : 'Chờ xác minh' }}{{ $shop->current_bank_account_id === $bank->id ? ' · Hiện hành' : '' }}
                    </p>
                    @can('verify', $bank)
                        @if (!$bank->verified_at)
                            <p class="small">Số tài khoản cần xác minh: <strong>{{ $bank->account_number }}</strong></p>
                            <form method="post" action="{{ route('banks.verify', $bank) }}">@csrf<x-field name="reason"
                                    :id="'bank-reason-' . $bank->id" label="Căn cứ xác minh" required /><button
                                    class="btn btn-outline-primary mt-3">Xác nhận đã xác minh</button></form>
                        @endif
                    @endcan
            </div>@empty<p class="text-secondary">Shop chưa đăng ký ngân hàng.</p>
            @endforelse
        </section>
    </div>
@endsection
