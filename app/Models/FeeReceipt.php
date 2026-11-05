<?php

namespace App\Models;

class FeeReceipt extends FinanceDocument
{
    public const STATUSES = ['confirmed' => 'Đã thu phí'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function allocations()
    {
        return $this->hasMany(FeeReceiptAllocation::class);
    }
}
