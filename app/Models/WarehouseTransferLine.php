<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseTransferLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['received_at' => 'datetime'];

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
