<?php

namespace App\Models;

class CashDeposit extends FinanceDocument
{
    public const STATUSES = ['submitted' => 'Chờ xác nhận', 'confirmed' => 'Đã nhận tiền', 'rejected' => 'Đã từ chối'];

    public function shipper()
    {
        return $this->belongsTo(User::class);
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function lines()
    {
        return $this->hasMany(CashDepositLine::class);
    }

    public function allocations()
    {
        return $this->hasMany(DepositAllocation::class);
    }
}
