@extends('layouts.portal')
{{-- Sửa hồ sơ cần duyệt lại; ngân hàng tạo version mới, địa chỉ và tài khoản cũ vẫn giữ lịch sử. --}}
@section('heading', 'Cửa hàng & địa chỉ')
@section('content')
    <div class="row g-4 g-lg-5">
        <section class="col-lg-6">
            <h2 class="h5 mb-3">Hồ sơ cửa hàng <span
                    class="status-badge">{{ \App\Models\Shop::STATUSES[$shop->status] }}</span></h2>
            @if ($shop->review_reason)
                <p class="alert alert-info">{{ $shop->review_reason }}</p>
            @endif
            <form method="post" action="{{ route('shop.update') }}">@csrf @method('PUT')
                <x-field name="name" label="Tên cửa hàng" :value="$shop->name" required />
                <x-field name="area_id" label="Tỉnh/thành" :value="$shop->area_id" :options="$areas" required />
                <x-field name="address" label="Địa chỉ" :value="$shop->address" required />
                <button class="btn btn-primary mt-3">Cập nhật hồ sơ</button>
            </form>
        </section>
        <section class="col-lg-6">
            <h2 class="h5 mb-3">Tài khoản nhận COD</h2>
            @if ($bank = $shop->currentBankAccount)
                <p><strong>{{ $bank->bank_name }}</strong> · {{ $bank->masked_number }}<br>{{ $bank->holder_name }}<br><span
                        class="status-badge">{{ $bank->verified_at ? 'Đã xác minh' : 'Chờ xác minh' }} · Phiên bản
                        {{ $bank->version }}</span></p>
            @endif
            <form method="post" action="{{ route('shop.bank') }}">@csrf
                <x-field name="bank_name" label="Ngân hàng" required /><x-field name="holder_name" label="Tên chủ tài khoản"
                    required /><x-field name="account_number" label="Số tài khoản mới" inputmode="numeric" maxlength="30"
                    hint="Thay đổi ngân hàng sẽ tạo phiên bản mới và cần xác minh lại." required />
                <button class="btn btn-outline-primary mt-3">Gửi thông tin ngân hàng</button>
            </form>
        </section>
    </div>
    <section class="work-section mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <h2>Địa chỉ lấy hàng</h2><a class="btn btn-outline-primary" href="{{ route('pickups.create') }}"><i
                    data-lucide="plus"></i> Thêm địa chỉ</a>
        </div>
        <div class="table-responsive">
            <table class="table work-table">
                <thead>
                    <tr>
                        <th>Liên hệ</th>
                        <th>Địa chỉ</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shop->addresses as $address)
                        <tr>
                            <td>{{ $address->contact_name }}<small>{{ $address->phone }}</small></td>
                            <td>{{ $address->address }}<small>{{ $address->area->name }}</small></td>
                            <td>{{ $address->is_active ? 'Đang dùng' : 'Ngừng dùng' }} @if ($address->is_default)
                                    <span class="status-badge">Mặc định</span>
                                @endif
                            </td>
                            <td><a class="btn btn-outline-primary" href="{{ route('pickups.edit', $address) }}"><i
                                        data-lucide="pencil"></i> Sửa</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
