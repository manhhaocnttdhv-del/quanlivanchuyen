<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopBankAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['account_number'];

    protected $casts = ['account_number' => 'encrypted', 'verified_at' => 'datetime'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function getMaskedNumberAttribute(): string
    {
        return '•••• '.$this->last_four;
    }
}
