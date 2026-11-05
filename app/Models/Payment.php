<?php

namespace App\Models;

class Payment extends FinanceDocument
{
    public const STATUSES = ['succeeded' => 'Thành công', 'failed' => 'Thất bại'];

    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function allocation()
    {
        return $this->hasOne(PaymentAllocation::class);
    }
}
