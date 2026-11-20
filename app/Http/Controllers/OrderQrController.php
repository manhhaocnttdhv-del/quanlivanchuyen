<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderScanRequest;
use App\Models\Order;
use App\Services\OrderQrService;
use Illuminate\Validation\ValidationException;

class OrderQrController extends Controller
{
    public function image(Order $order, OrderQrService $qr)
    {
        $this->authorize('view', $order);

        return response($qr->png($order))->withHeaders([
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="'.$order->tracking_code.'-qr.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function scan(OrderScanRequest $request)
    {
        return view('portal.orders.scan');
    }

    public function resolve(OrderScanRequest $request, OrderQrService $qr)
    {
        $destination = $qr->destination($request->user(), $request->validated('code'));
        if (! $destination) {
            throw ValidationException::withMessages(['code' => 'Không tìm thấy vận đơn hoặc nhiệm vụ trong phạm vi của bạn.']);
        }

        return redirect()->to($destination);
    }
}
