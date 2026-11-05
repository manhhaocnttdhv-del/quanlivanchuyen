@php
    $isReturn = $task->type === 'return';
    $return = $order->returnRecord;
    $expectedCod = $isReturn ? 0 : (int) $order->cod_amount;
    $expectedFee = $isReturn
        ? ($return?->fee_payment === 'collect_on_return'
            ? (int) $return->fee_amount
            : 0)
        : ($order->fee_payer === 'recipient'
            ? (int) $order->shipping_fee
            : 0);
    $failed = old('outcome') === 'failed';
    $clientToken = old('client_token', (string) \Illuminate\Support\Str::uuid());
@endphp
<section class="work-section delivery-result">
    <h2>Kết quả {{ $isReturn ? 'hoàn hàng' : 'giao hàng' }}</h2>
    <dl class="delivery-money mt-3">
        <div>
            <dt>COD dự kiến</dt>
            <dd>{{ number_format($expectedCod, 0, ',', '.') }}đ</dd>
        </div>
        <div>
            <dt>{{ $isReturn ? 'Phí hoàn thu tại chỗ' : 'Cước thu người nhận' }}</dt>
            <dd>{{ number_format($expectedFee, 0, ',', '.') }}đ</dd>
        </div>
        <div>
            <dt>Tổng cần thu</dt>
            <dd>{{ number_format($expectedCod + $expectedFee, 0, ',', '.') }}đ</dd>
        </div>
    </dl>
    @if ($isReturn && $return)
        <p class="small text-secondary">Phí hoàn đã chốt: {{ number_format((int) $return->fee_amount, 0, ',', '.') }}đ ·
            {{ \App\Models\ReturnRecord::PAYMENTS[$return->fee_payment] }}. Không thu COD khi hoàn.</p>
    @endif
    <ul class="nav nav-tabs mt-4" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link {{ !$failed ? 'active' : '' }}" id="success-tab"
                data-bs-toggle="tab" data-bs-target="#success-result" type="button" role="tab"
                aria-controls="success-result" aria-selected="{{ !$failed ? 'true' : 'false' }}"><i
                    data-lucide="circle-check"></i> Thành công</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link {{ $failed ? 'active' : '' }}" id="failure-tab"
                data-bs-toggle="tab" data-bs-target="#failure-result" type="button" role="tab"
                aria-controls="failure-result" aria-selected="{{ $failed ? 'true' : 'false' }}"><i data-lucide="x"></i>
                Thất bại</button></li>
    </ul>
    <div class="tab-content pt-4 narrow-form">
        <div class="tab-pane {{ !$failed ? 'show active' : '' }}" id="success-result" role="tabpanel"
            aria-labelledby="success-tab" tabindex="0">
            <form method="post" enctype="multipart/form-data" action="{{ route('delivery.record', $task) }}"
                data-confirm="Xác nhận đã bàn giao đủ kiện và kiểm tra tiền thực thu?">
                @csrf<input type="hidden" name="outcome" value="success"><input type="hidden" name="client_token"
                    value="{{ $clientToken }}">
                <div class="row g-3">
                    <div class="col-12"><x-field name="receiver_name" label="Tên người nhận thực tế" required
                            maxlength="150" autocomplete="off" /></div>
                    @if (!$isReturn)
                        <div class="col-sm-6"><x-field name="actual_cod" type="number" label="COD thực thu (đ)"
                                min="0" max="9999999999999" step="1" inputmode="numeric" :value="$expectedCod"
                                required /></div>
                    @endif
                    <div class="col-sm-6"><x-field name="actual_fee" type="number" :label="($isReturn ? 'Phí hoàn' : 'Cước') . ' thực thu (đ)'" min="0"
                            max="9999999999999" step="1" inputmode="numeric" :value="$expectedFee" required /></div>
                    <div class="col-12"><x-field name="financial_reason" type="textarea"
                            label="Lý do chênh tiền (nếu có)" minlength="5" maxlength="1000" rows="2" /></div>
                    <div class="col-12"><x-field name="evidence" type="file" label="Ảnh bàn giao / người nhận"
                            accept="image/jpeg,image/png,image/webp" capture="environment" required />
                        <p class="small text-secondary mt-1">JPG, PNG hoặc WebP, tối đa 5 MB.</p>
                    </div>
                    <div class="col-12"><x-field name="reason" id="success-reason" type="textarea"
                            label="Ghi nhận bàn giao" minlength="5" maxlength="1000" rows="2" required /></div>
                </div>
                <button class="btn btn-primary mt-4 delivery-submit"><i data-lucide="circle-check"></i> Xác nhận
                    {{ $isReturn ? 'hoàn' : 'giao' }} thành công</button>
            </form>
        </div>
        <div class="tab-pane {{ $failed ? 'show active' : '' }}" id="failure-result" role="tabpanel"
            aria-labelledby="failure-tab" tabindex="0">
            <form method="post" enctype="multipart/form-data" action="{{ route('delivery.record', $task) }}"
                data-confirm="Ghi nhận thất bại và chuyển nhiệm vụ sang chờ trả kiện về kho?">
                @csrf<input type="hidden" name="outcome" value="failed"><input type="hidden" name="client_token"
                    value="{{ $clientToken }}">
                <div class="row g-3">
                    <div class="col-12"><x-field name="reason_code" label="Lý do thất bại" :options="\App\Models\DeliveryAttempt::REASONS"
                            placeholder="Chọn lý do" required /></div>
                    <div class="col-12"><x-field name="reason" id="failure-reason" type="textarea"
                            label="Diễn biến / ý kiến người nhận" minlength="5" maxlength="1000" rows="3"
                            required /></div>
                    <div class="col-12"><x-field name="evidence" id="failure-evidence" type="file"
                            label="Ảnh bằng chứng" accept="image/jpeg,image/png,image/webp" capture="environment" />
                        <p class="small text-secondary mt-1">Bắt buộc khi hàng hỏng hoặc không tìm thấy kiện. Tối đa 5
                            MB.</p>
                    </div>
                </div>
                <button class="btn btn-outline-danger mt-4 delivery-submit"><i data-lucide="rotate-ccw"></i> Ghi nhận
                    thất bại</button>
            </form>
        </div>
    </div>
</section>
