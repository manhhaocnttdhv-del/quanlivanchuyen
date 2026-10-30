@extends('layouts.portal')
{{-- Nháp được sửa trực tiếp; đơn approved gửi đề nghị. Token chống tạo lặp, revision chống ghi đè bản cũ. --}}
@section('heading', $order ? ($order->status === 'approved' ? 'Đề nghị chỉnh sửa vận đơn' : 'Chỉnh sửa vận đơn') : 'Tạo
    vận đơn')
@section('actions')<a class="btn btn-outline-primary"
        href="{{ $order ? route('orders.show', $order) : route('orders.index') }}">
    <i data-lucide="arrow-left"></i> Quay lại</a>@endsection
@section('content')
    @php
        $isChange = $order?->status === 'approved';
        $data = $order?->inputData() ?? [
            'service' => 'standard',
            'cod_amount' => 0,
            'fee_payer' => 'sender',
            'parcel_count' => 1,
        ];
        // Chỉ giữ dòng dạng mảng và đánh lại chỉ số sau lỗi validation để JS thêm/xóa không trùng name.
        $items = collect((array) old('items', $data['items'] ?? [['name' => '', 'quantity' => '']]))
            ->filter(fn($item) => is_array($item))
            ->values();
        if ($items->isEmpty()) {
            $items = collect([['name' => '', 'quantity' => '']]);
        }
        $pickupOptions = $pickups
            ->mapWithKeys(
                fn($pickup) => [
                    $pickup->id => $pickup->contact_name . ' · ' . $pickup->address . ', ' . $pickup->area->name,
                ],
            )
            ->all();
    @endphp
    @if ($shop->status !== 'approved')
        <div class="alert alert-warning">Cửa hàng chưa được duyệt. Bạn có thể lưu bản nháp và gửi sau khi hồ sơ được duyệt.
        </div>
    @endif
    @if ($order?->pendingChange)
        <div class="alert alert-warning">Đã có đề nghị đang chờ xử lý. Vui lòng chờ kết quả trước khi tạo đề nghị mới.</div>
    @endif
    <form method="post" class="edit-form order-edit"
        action="{{ $isChange ? route('orders.change', $order) : ($order ? route('orders.update', $order) : route('orders.store')) }}">
        @csrf
        @if ($order && !$isChange)
            @method('PUT')
        @endif
        <x-client-token :value="$clientToken" />
        @if ($order)
            <input type="hidden" name="revision" value="{{ $order->revision }}">
        @endif
        <section class="order-form-section">
            <div class="section-number">01</div>
            <div class="order-form-fields">
                <h2>Thông tin lấy hàng</h2>
                <x-field name="pickup_address_id" label="Địa chỉ lấy hàng" :options="$pickupOptions" :value="$data['pickup_address_id'] ?? $pickups->firstWhere('is_default', true)?->id"
                    placeholder="Chọn địa chỉ lấy hàng" />
                <a class="small d-inline-block mt-2" href="{{ route('shop.profile') }}">Quản lý địa chỉ lấy hàng <i
                        data-lucide="arrow-up-right"></i></a>
            </div>
        </section>
        <section class="order-form-section">
            <div class="section-number">02</div>
            <div class="order-form-fields">
                <h2>Thông tin người nhận</h2>
                <div class="row g-3">
                    <div class="col-md-6"><x-field name="recipient_name" label="Họ tên người nhận" :value="$data['recipient_name'] ?? ''"
                            maxlength="150" /></div>
                    <div class="col-md-6"><x-field name="recipient_phone" label="Điện thoại người nhận" :value="$data['recipient_phone'] ?? ''"
                            maxlength="10" inputmode="tel" /></div>
                    <div class="col-md-6"><x-field name="area_id" label="Tỉnh / thành nhận" :options="$areas"
                            :value="$data['area_id'] ?? ''" placeholder="Chọn tỉnh / thành" /></div>
                    <div class="col-md-6"><x-field name="recipient_address" label="Địa chỉ chi tiết" :value="$data['recipient_address'] ?? ''"
                            maxlength="500" /></div>
                </div>
            </div>
        </section>
        <section class="order-form-section">
            <div class="section-number">03</div>
            <div class="order-form-fields">
                <h2>Hàng hóa và dịch vụ</h2>
                <div id="order-item-rows">
                    @foreach ($items as $i => $item)
                        <div class="order-item-row">
                            <div><label class="form-label" for="item-name-{{ $i }}">Tên hàng</label><input
                                    class="form-control @error('items.' . $i . '.name') is-invalid @enderror"
                                    id="item-name-{{ $i }}" name="items[{{ $i }}][name]"
                                    value="{{ is_scalar($item['name'] ?? '') ? $item['name'] ?? '' : '' }}"
                                    maxlength="200">
                                @error('items.' . $i . '.name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div><label class="form-label" for="item-quantity-{{ $i }}">Số lượng</label><input
                                    class="form-control @error('items.' . $i . '.quantity') is-invalid @enderror"
                                    id="item-quantity-{{ $i }}" type="number"
                                    name="items[{{ $i }}][quantity]"
                                    value="{{ is_scalar($item['quantity'] ?? '') ? $item['quantity'] ?? '' : '' }}"
                                    min="1" max="9999">
                                @error('items.' . $i . '.quantity')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button class="btn btn-outline-danger remove-order-item" type="button"
                                aria-label="Xóa mặt hàng" title="Xóa mặt hàng"><i data-lucide="trash-2"></i></button>
                        </div>
                    @endforeach
                </div>
                <button class="btn btn-outline-primary mt-2" type="button" id="add-order-item"><i data-lucide="plus"></i>
                    Thêm mặt hàng</button>
                <div class="row g-3 mt-2">
                    <div class="col-md-4"><x-field name="weight_grams" label="Tổng khối lượng (g)" type="number"
                            :value="$data['weight_grams'] ?? ''" min="1" max="20000" step="1" /></div>
                    <div class="col-md-4"><x-field name="parcel_count" label="Số kiện" type="number" :value="$data['parcel_count']"
                            min="1" max="20" required /></div>
                    <div class="col-md-4"><x-field name="service" label="Dịch vụ" :options="['standard' => 'Tiêu chuẩn', 'express' => 'Nhanh']" :value="$data['service']"
                            required /></div>
                </div>
                <div class="form-text">Khối lượng là tổng cân nặng của toàn bộ kiện trong vận đơn.</div>
            </div>
        </section>
        <section class="order-form-section">
            <div class="section-number">04</div>
            <div class="order-form-fields">
                <h2>Thu hộ và thanh toán</h2>
                <div class="row g-3">
                    <div class="col-md-6"><x-field name="cod_amount" label="Tiền hàng thu hộ COD (đ)" type="number"
                            :value="$data['cod_amount']" min="0" step="1" required /></div>
                    <div class="col-md-6"><x-field name="fee_payer" label="Người trả phí vận chuyển" :options="['sender' => 'Cửa hàng trả phí', 'recipient' => 'Người nhận trả phí']"
                            :value="$data['fee_payer']" required /></div>
                </div>
                <x-field name="note" label="Ghi chú giao hàng" type="textarea" :value="$data['note'] ?? ''" rows="2"
                    maxlength="1000" />
                @if ($isChange)
                    <x-field name="reason" label="Lý do đề nghị thay đổi" type="textarea" rows="3"
                        minlength="5" maxlength="1000" required />
                @endif
            </div>
        </section>
        <div class="form-actions"><button class="btn btn-primary" @disabled($order?->pendingChange)><i
                    data-lucide="{{ $isChange ? 'send' : 'save' }}"></i>
                {{ $isChange ? 'Gửi đề nghị thay đổi' : 'Lưu và xem trước' }}</button></div>
    </form>
@endsection
