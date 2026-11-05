{{-- In từng kiện từ snapshot đã chốt; barcode là SVG do thư viện tạo từ mã server, không render HTML người nhập. --}}
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tem {{ $order->tracking_code }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="print-body">
    <div class="print-actions"><a class="btn btn-outline-primary" href="{{ route('orders.show', $order) }}"><i
                data-lucide="arrow-left"></i> Vận đơn</a><button class="btn btn-primary" data-print><i
                data-lucide="printer"></i> In tem</button></div>
    @for ($parcel = 1; $parcel <= $order->parcel_count; $parcel++)
        <article class="shipping-label">
            <header><strong>Gửi Việt.</strong><span>{{ config('shipping.services.' . $order->service . '.label') }} · Kiện
                    {{ $parcel }}/{{ $order->parcel_count }}</span></header>
            <div class="label-barcode">{!! $barcode !!}<strong>{{ $order->tracking_code }}</strong></div>
            <section><small>NGƯỜI GỬI ·
                    {{ $order->sender_snapshot['shop'] }}</small><strong>{{ $order->sender_snapshot['name'] }} ·
                    {{ $order->sender_snapshot['phone'] }}</strong>
                <p>{{ $order->sender_snapshot['address'] }}, {{ $order->sender_snapshot['area'] }}</p>
            </section>
            <section><small>NGƯỜI NHẬN</small><strong>{{ $order->recipient_snapshot['name'] }} ·
                    {{ $order->recipient_snapshot['phone'] }}</strong>
                <p>{{ $order->recipient_snapshot['address'] }}, {{ $order->recipient_snapshot['area'] }}</p>
            </section>
            <section class="label-amount">
                <div><small>COD toàn
                        đơn</small><strong>{{ number_format((int) $order->cod_amount, 0, ',', '.') }}đ</strong></div>
                <div><small>Cước ·
                        {{ $order->fee_payer === 'sender' ? 'Người gửi trả' : 'Người nhận trả' }}</small><strong>{{ number_format((int) $order->shipping_fee, 0, ',', '.') }}đ</strong>
                </div>
                <div><small>TỔNG THU NGƯỜI NHẬN · MỘT LẦN /
                        ĐƠN</small><strong>{{ number_format((int) $order->recipient_total, 0, ',', '.') }}đ</strong>
                </div>
            </section>
            <section>
                <p>{{ $order->items->map(fn($item) => $item->name . ' × ' . $item->quantity)->join('; ') }}</p>
                <p>{{ $order->weight_grams }}g toàn đơn · {{ $order->note }}</p>
            </section>
        </article>
    @endfor
</body>

</html>
