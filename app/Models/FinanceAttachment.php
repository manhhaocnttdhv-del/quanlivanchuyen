<?php

namespace App\Models;

class FinanceAttachment extends FinanceLine
{
    protected $hidden = ['path', 'content_sha256'];

    public function document(): FinanceDocument
    {
        foreach (['cash_deposit_id' => CashDeposit::class, 'payment_id' => Payment::class, 'fee_receipt_id' => FeeReceipt::class, 'adjustment_id' => Adjustment::class] as $key => $model) {
            if ($this->$key) {
                return $model::findOrFail($this->$key);
            }
        }
        abort(404);
    }
}
