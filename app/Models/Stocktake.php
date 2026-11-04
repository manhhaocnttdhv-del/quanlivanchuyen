<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stocktake extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot_at' => 'datetime', 'approved_at' => 'datetime'];

    public function lines()
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function discrepancies()
    {
        return $this->hasMany(Discrepancy::class);
    }
}
