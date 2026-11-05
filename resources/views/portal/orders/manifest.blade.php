<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bảng kê vận đơn</title>@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="print-body">
    <div class="print-actions"><a class="btn btn-outline-primary" href="{{ route('orders.index', request()->query()) }}"><i
                data-lucide="arrow-left"></i> Danh sách</a><button class="btn btn-primary" data-print><i
                data-lucide="printer"></i> In bảng kê</button></div>
    <main class="manifest-sheet">
        <header><strong>Gửi Việt.</strong>
            <h1>Bảng kê vận đơn</h1>
            <p>{{ now()->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }} · Người in: {{ auth()->user()->name }}</p>
        </header>
        <p>{{ $orders->count() }} vận đơn · <strong>{{ $orders->sum('parcel_count') }} kiện</strong> · COD:
            {{ number_format($orders->sum(fn($order) => (int) $order->cod_amount), 0, ',', '.') }}đ · Cước:
            {{ number_format($orders->sum(fn($order) => (int) $order->shipping_fee), 0, ',', '.') }}đ</p>
        <div class="table-responsive">
            <table class="table manifest-table">
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã / người gửi</th>
                        <th>Người nhận</th>
                        <th>Kiện / g</th>
                        <th>COD</th>
                        <th>Cước</th>
                        <th>Thu người nhận</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $order->tracking_code }}<br>{{ $order->shop->name }}</td>
                            <td>{{ $order->recipient_snapshot['name'] }} ·
                                {{ $order->recipient_snapshot['phone'] }}<br>{{ $order->recipient_snapshot['address'] }},
                                {{ $order->recipient_snapshot['area'] }}</td>
                            <td>{{ $order->parcel_count }} / {{ $order->weight_grams }}</td>
                            <td>{{ number_format((int) $order->cod_amount, 0, ',', '.') }}</td>
                            <td>{{ number_format((int) $order->shipping_fee, 0, ',', '.') }}<br>{{ $order->fee_payer === 'sender' ? 'Người gửi trả' : 'Người nhận trả' }}
                            </td>
                            <td>{{ number_format((int) $order->recipient_total, 0, ',', '.') }}</td>
                    </tr>@empty<tr>
                            <td colspan="7">Không có vận đơn đủ điều kiện in trong bộ lọc này.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="small">Bảng kê danh sách vận đơn, chưa phải biên bản xác nhận đã bàn giao kiện hàng.</p>
    </main>
</body>

</html>
