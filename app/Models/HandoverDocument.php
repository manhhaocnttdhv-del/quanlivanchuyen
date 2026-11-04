<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HandoverDocument extends Model
{
    protected $guarded = ['id', 'pending_task_id'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function task()
    {
        return $this->belongsTo(DeliveryTask::class, 'delivery_task_id');
    }

    public function shipper()
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function lines()
    {
        return $this->hasMany(HandoverLine::class);
    }
}
