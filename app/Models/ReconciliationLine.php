<?php

namespace App\Models;

class ReconciliationLine extends FinanceLine
{
    public function reconciliation()
    {
        return $this->belongsTo(Reconciliation::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function collection()
    {
        return $this->belongsTo(DeliveryCollection::class, 'delivery_collection_id');
    }

    public function receivable()
    {
        return $this->belongsTo(Receivable::class);
    }

    public function adjustment()
    {
        return $this->belongsTo(Adjustment::class);
    }
}
