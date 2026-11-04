<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HandoverLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['received' => 'boolean'];

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
