<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StocktakeLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['actual_present' => 'boolean', 'movement_detected' => 'boolean'];

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
