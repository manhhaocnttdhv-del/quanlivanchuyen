{{-- Kết quả từ FeeCalculator cho form thường; cước chỉ được chốt lại khi gửi duyệt vận đơn. --}}
<div class="quote-label">Cước tham khảo · {{ $quote['route'] }}</div>
<div class="quote-price">{{ number_format($quote['total'], 0, ',', '.') }} <small>đ</small></div>
<p class="small mb-3">{{ $quote['service'] }} · Dự kiến {{ $quote['days'] }}</p>
<dl class="quote-breakdown">
    <div>
        <dt>Cước cơ bản (đến {{ $quote['base_weight_grams'] }}g)</dt>
        <dd>{{ number_format($quote['base_fee'], 0, ',', '.') }}đ</dd>
    </div>
    <div>
        <dt>Khối lượng thêm</dt>
        <dd>{{ number_format($quote['weight_fee'], 0, ',', '.') }}đ</dd>
    </div>
</dl>
<p class="quote-disclaimer">{{ $quote['version'] }} · Cước được tính lại và chốt khi gửi duyệt.</p>
