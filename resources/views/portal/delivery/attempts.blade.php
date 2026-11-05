{{-- Kết quả giao/hoàn là lịch sử đã ghi; bằng chứng mở qua route kiểm quyền, không dùng path storage trực tiếp. --}}
<section class="work-section">
    <h2>Kết quả giao / hoàn</h2>
    @if ($order->financial_hold)
        <p class="alert alert-warning mt-3">Có chênh lệch tiền thực thu đang chờ xử lý tài chính.</p>
    @endif
    @if ($order->returnRecord)
        <p class="small mt-3">Phí hoàn đã chốt:
            <strong>{{ number_format((int) $order->returnRecord->fee_amount, 0, ',', '.') }}đ</strong> ·
            {{ \App\Models\ReturnRecord::PAYMENTS[$order->returnRecord->fee_payment] }} ·
            {{ $order->returnRecord->fee_charged_at ? 'Đã phát sinh khi hoàn thành công' : 'Chưa phát sinh' }}</p>
    @endif
    @forelse ($attempts as $attempt)
        <article class="delivery-attempt">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <strong>{{ $attempt->type === 'return' ? 'Hoàn' : 'Giao' }} lần {{ $attempt->attempt_number }} ·
                    {{ $attempt->outcome === 'success' ? 'Thành công' : 'Thất bại' }}</strong><time>{{ $attempt->attempted_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time>
            </div>
            @if ($attempt->outcome === 'failed')
                <p class="mt-2 mb-1">{{ \App\Models\DeliveryAttempt::REASONS[$attempt->reason_code] }}</p>
            @endif
            <p class="small mt-2">{{ $attempt->shipper->name }} · {{ $attempt->reason }}</p>
            @if ($attempt->outcome === 'success')
                <p>Người nhận: {{ $attempt->proof?->receiver_name }}</p>
                <dl class="delivery-money">
                    <div>
                        <dt>COD thực thu</dt>
                        <dd>{{ number_format((int) $attempt->actual_cod, 0, ',', '.') }}đ</dd>
                    </div>
                    <div>
                        <dt>Phí thực thu</dt>
                        <dd>{{ number_format((int) $attempt->actual_fee, 0, ',', '.') }}đ</dd>
                    </div>
                    <div>
                        <dt>Tiền dự kiến</dt>
                        <dd>{{ number_format((int) $attempt->expected_cod + (int) $attempt->expected_fee, 0, ',', '.') }}đ
                        </dd>
                    </div>
                </dl>
                @if ($attempt->financial_status === 'disputed')
                    <p class="text-danger small">Chênh lệch: {{ $attempt->financial_reason }}</p>
                @endif
            @endif
            @if ($attempt->proof?->getRawOriginal('evidence_path'))
                <a href="{{ route('delivery.proof', $attempt) }}" target="_blank" rel="noopener"><i
                        data-lucide="image"></i> Xem ảnh bằng chứng</a>
            @endif
        </article>
    @empty
        <p class="text-secondary mt-3">Chưa ghi nhận kết quả giao / hoàn.</p>
    @endforelse
</section>
