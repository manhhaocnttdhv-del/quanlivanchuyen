@extends('layouts.customer')
@section('title', 'Đăng ký gửi hàng | Gửi Việt')
@section('content')
    @php
        $selectedArea = old('area_id', '');
        $selectedAreaId = is_scalar($selectedArea) ? (string) $selectedArea : '';
        $selectedAreaName = $areas->get($selectedAreaId);
        $warehouseCount = $warehouses->where('area_id', $selectedAreaId)->count();
        $accountType = old('account_type', 'individual');
        $isBusiness = $accountType === 'business';
    @endphp
    <main class="auth-band">
        <div class="container">
            <div class="auth-form auth-wide">
                <h1>Đăng ký gửi hàng</h1>
                <p class="text-secondary">Hồ sơ người gửi được xét duyệt trước khi gửi vận đơn.</p>
                <form method="post" action="{{ route('register.store') }}" data-registration-form novalidate>@csrf
                    <fieldset class="mb-4">
                        <legend class="form-label fs-6">Loại người gửi</legend>
                        <div class="registration-types">
                            <input class="btn-check" type="radio" name="account_type" id="account-type-individual"
                                value="individual" @checked(! $isBusiness) aria-describedby="account-type-feedback" required>
                            <label class="btn btn-outline-primary" for="account-type-individual"><i data-lucide="user-round" aria-hidden="true"></i> Cá nhân</label>
                            <input class="btn-check" type="radio" name="account_type" id="account-type-business"
                                value="business" @checked($isBusiness) aria-describedby="account-type-feedback" required>
                            <label class="btn btn-outline-primary" for="account-type-business"><i data-lucide="store" aria-hidden="true"></i> Cửa hàng</label>
                        </div>
                        <div id="account-type-feedback" class="invalid-feedback @error('account_type') d-block @enderror" data-error-for="account_type">@error('account_type'){{ $message }}@enderror</div>
                    </fieldset>
                    <div class="row g-3">
                        <div class="{{ $isBusiness ? 'col-md-6' : 'col-12' }}" data-sender-name><x-field name="name" label="Họ và tên" maxlength="100" autocomplete="name" required /></div>
                        <div class="col-md-6" data-business-field @if (! $isBusiness) hidden @endif>
                            <x-field name="shop_name" label="Tên cửa hàng" maxlength="150" :required="$isBusiness" :disabled="! $isBusiness" />
                        </div>
                        <div class="col-md-6"><x-field name="email" label="Email" type="email" autocomplete="email"
                                required /></div>
                        <div class="col-md-6"><x-field name="phone" label="Số điện thoại" type="tel" maxlength="10"
                                required /></div>
                        <div class="col-md-6"><x-field name="password" label="Mật khẩu" type="password"
                                hint="Ít nhất 10 ký tự." autocomplete="new-password" required /></div>
                        <div class="col-md-6"><x-field name="password_confirmation" label="Xác nhận mật khẩu"
                                type="password" autocomplete="new-password" required /></div>
                        <div class="col-md-5"><x-field name="area_id" label="Tỉnh/thành" :options="$areas"
                                placeholder="Chọn tỉnh/thành" required /></div>
                        <div class="col-md-7"><x-field name="address" label="Địa chỉ lấy hàng" required /></div>
                        <div class="col-12">
                            <section class="registration-warehouses" aria-labelledby="registration-warehouses-heading">
                                <h2 id="registration-warehouses-heading"><i data-lucide="map-pin" aria-hidden="true"></i> Kho tiếp nhận trong khu vực</h2>
                                <p class="small text-secondary mb-0" data-warehouse-summary role="status" aria-live="polite" aria-atomic="true">
                                    @if (! $selectedAreaName)
                                        Chưa chọn tỉnh/thành.
                                    @elseif ($warehouseCount === 0)
                                        Chưa có kho tiếp nhận đang hoạt động tại {{ $selectedAreaName }}.
                                    @else
                                        {{ $selectedAreaName }}: {{ $warehouseCount }} kho đang hoạt động.
                                    @endif
                                </p>
                                <ul class="list-unstyled mb-0">
                                    @foreach ($warehouses as $warehouse)
                                        <li class="registration-warehouse" data-warehouse-area="{{ $warehouse->area_id }}"
                                            @if ((string) $warehouse->area_id !== $selectedAreaId) hidden @endif>
                                            <div class="d-flex align-items-baseline flex-wrap gap-2">
                                                <strong>{{ $warehouse->name }}</strong>
                                                <span class="small text-secondary">{{ $warehouse->code }}</span>
                                            </div>
                                            <p class="small text-secondary mb-0">{{ $warehouse->address }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        </div>
                    </div><button class="btn btn-primary mt-4">Đăng ký gửi hàng</button><a class="btn btn-link mt-4"
                        href="{{ route('login') }}">Đã có tài khoản</a></form>
            </div>
        </div>
    </main>
@endsection
