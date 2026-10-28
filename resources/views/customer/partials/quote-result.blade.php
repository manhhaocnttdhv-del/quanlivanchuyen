<div class="quote-label">Cước tham khảo · {{ $quote['route'] }}</div>
<div class="quote-price">{{ number_format($quote['total'], 0, ',', '.') }} <small>đ</small></div>
<p class="small mb-3">{{ $quote['service'] }} · Dự kiến {{ $quote['days'] }}</p>
<dl class="quote-breakdown">
    <div><dt>Cước cơ bản (đến 500g)</dt><dd>{{ number_format($quote['base_fee'], 0, ',', '.') }}đ</dd></div>
    <div><dt>Khối lượng thêm</dt><dd>{{ number_format($quote['weight_fee'], 0, ',', '.') }}đ</dd></div>
    <div><dt>Phụ phí dịch vụ nhanh</dt><dd>{{ number_format($quote['service_fee'], 0, ',', '.') }}đ</dd></div>
</dl>
<p class="quote-disclaimer">Biểu phí mẫu, chưa gồm phụ phí phát sinh. Cước và thời gian sẽ được xác nhận khi tiếp nhận kiện.</p>
