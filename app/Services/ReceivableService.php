<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Receivable;
use App\Models\User;
use App\Support\Money;

class ReceivableService
{
    public function __construct(private AuditService $audit) {}

    public function accrue(Order $order, ?User $actor = null): void
    {
        // Phi shop chiu phat sinh khi giao/hoan thanh cong; khong thu phi tren don huy/nhap.
        if (! in_array($order->status, ['delivered', 'returned'])) {
            return;
        }
        $fees = [];
        if ($order->fee_payer === 'sender') {
            $fees['shipping_fee'] = Money::value($order->shipping_fee);
        }
        $return = $order->returnRecord;
        if ($order->status === 'returned' && $return?->fee_charged_at && $return->fee_payment === 'reconciliation') {
            $fees['return_fee'] = Money::value($return->fee_amount);
        }
        foreach ($fees as $type => $amount) {
            if ($amount > 0) {
                $fee = Receivable::firstOrCreate(['order_id' => $order->id, 'type' => $type], ['shop_id' => $order->shop_id, 'warehouse_id' => $order->warehouse_id, 'amount' => $amount, 'accrued_at' => $order->returned_at ?? $order->delivered_at ?? now()]);
                if ($fee->wasRecentlyCreated) {
                    $this->audit->record($actor, $fee, 'finance.fee_accrued', [], $fee->toArray(), 'Ghi nhận phí shop chịu từ vận đơn hoàn tất');
                }
            }
        }
    }
}
