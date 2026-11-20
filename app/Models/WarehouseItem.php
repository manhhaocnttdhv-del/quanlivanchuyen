<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['actual_weight_kg' => 'decimal:3'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function holder()
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function discrepancies()
    {
        return $this->hasMany(Discrepancy::class);
    }
}
