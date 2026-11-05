<?php

namespace App\Models;

class DepositAllocation extends FinanceLine
{
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deposit()
    {
        return $this->belongsTo(CashDeposit::class, 'cash_deposit_id');
    }

    public function collection()
    {
        return $this->belongsTo(DeliveryCollection::class, 'delivery_collection_id');
    }
}
