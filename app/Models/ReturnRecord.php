<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReturnRecord extends Model
{
    public const PAYMENTS = ['reconciliation' => 'Tính vào đối soát shop', 'collect_on_return' => 'Thu tại lúc hoàn'];

    protected $guarded = ['id'];

    protected $casts = ['fee_amount' => 'decimal:2', 'fee_snapshot' => 'array', 'returned_at' => 'datetime', 'fee_charged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->isDirty(['order_id', 'warehouse_id', 'authorized_by', 'fee_payment', 'fee_amount', 'fee_snapshot', 'reason'])) {
                throw new LogicException('Căn cứ và phí hoàn đã chốt không được sửa.');
            }
        });
        static::deleting(fn () => throw new LogicException('Không xóa hồ sơ hoàn hàng.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
