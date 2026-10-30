<?php

namespace App\Models;

class FeeReceiptAllocation extends FinanceLine
{
    public function receipt()
    {
        return $this->belongsTo(FeeReceipt::class, 'fee_receipt_id');
    }

    public function receivable()
    {
        return $this->belongsTo(Receivable::class);
    }
}
