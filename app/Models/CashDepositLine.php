<?php

namespace App\Models;

class CashDepositLine extends FinanceLine
{
    public function collection()
    {
        return $this->belongsTo(DeliveryCollection::class, 'delivery_collection_id');
    }
}
