<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryTask extends Model
{
    public const TYPES = ['pickup' => 'Lấy hàng', 'delivery' => 'Giao hàng', 'return' => 'Hoàn hàng'];

    public const STATUSES = ['assigned' => 'Chờ nhận kiện', 'in_custody' => 'Đang giữ kiện', 'recall_pending' => 'Chờ trả về kho', 'completed' => 'Đã bàn giao về kho', 'cancelled' => 'Đã thu hồi', 'failed' => 'Không nhận được hàng'];

    public const ACTIVE = ['assigned', 'in_custody', 'recall_pending'];

    protected $guarded = ['id', 'active_order_id'];

    protected $attributes = ['status' => 'assigned'];

    protected $casts = ['finished_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shipper()
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function pickupAttempt()
    {
        return $this->hasOne(PickupAttempt::class);
    }

    public function handovers()
    {
        return $this->hasMany(HandoverDocument::class)->latest('id');
    }
}
