<?php

namespace App\Models;

class Receivable extends FinanceLine
{
    public const TYPES = ['shipping_fee' => 'Cước shop chịu', 'return_fee' => 'Phí hoàn shop chịu', 'reconciliation_debt' => 'Nợ sau đối soát'];

    protected $casts = ['amount' => 'decimal:2', 'accrued_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function receiptAllocations()
    {
        return $this->hasMany(FeeReceiptAllocation::class);
    }

    public function adjustmentLines()
    {
        return $this->hasMany(AdjustmentLine::class);
    }

    public function reconciliationLines()
    {
        return $this->hasMany(ReconciliationLine::class);
    }
}
