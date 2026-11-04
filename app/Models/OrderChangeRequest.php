<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderChangeRequest extends Model
{
    protected $guarded = ['id', 'pending_order_id'];

    protected $hidden = ['pending_order_id'];

    protected $casts = ['changes' => 'array', 'before_values' => 'array', 'reviewed_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
