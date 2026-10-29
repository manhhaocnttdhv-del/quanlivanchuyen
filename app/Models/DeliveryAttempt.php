<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryAttempt extends Model
{
    public const REASONS = ['absent' => 'Không liên hệ được / vắng mặt', 'refused' => 'Từ chối nhận', 'address' => 'Sai / thiếu địa chỉ', 'damaged' => 'Hàng hư hỏng', 'lost' => 'Không tìm thấy kiện', 'other' => 'Lý do khác'];

    protected $guarded = ['id'];

    protected $hidden = ['client_token', 'request_hash'];

    protected $casts = ['attempted_at' => 'datetime', 'expected_cod' => 'decimal:2', 'expected_fee' => 'decimal:2', 'actual_cod' => 'decimal:2', 'actual_fee' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lần giao chỉ được ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Không xóa lịch sử giao hàng.'));
    }

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

    public function proof()
    {
        return $this->hasOne(DeliveryProof::class);
    }

    public function collections()
    {
        return $this->hasMany(DeliveryCollection::class);
    }
}
