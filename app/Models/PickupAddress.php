<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickupAddress extends Model
{
    protected $fillable = ['area_id', 'contact_name', 'phone', 'address', 'is_active', 'is_default'];

    protected $casts = ['is_active' => 'boolean', 'is_default' => 'boolean'];

    protected $hidden = ['default_shop_id'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
