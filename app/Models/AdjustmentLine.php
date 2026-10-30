<?php

namespace App\Models;

class AdjustmentLine extends FinanceLine
{
    protected $casts = ['amount' => 'decimal:2', 'verified_amount' => 'decimal:2'];

    public function adjustment()
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function collection()
    {
        return $this->belongsTo(DeliveryCollection::class, 'delivery_collection_id');
    }

    public function receivable()
    {
        return $this->belongsTo(Receivable::class);
    }
}
