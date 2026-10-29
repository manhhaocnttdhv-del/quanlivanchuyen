<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryDecision extends Model
{
    public const TYPES = ['retry' => 'Giao lại trong giới hạn', 'retry_exception' => 'Cho phép thêm một lượt vượt giới hạn', 'return' => 'Hoàn hàng'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quyết định chỉ được ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Không xóa quyết định giao/hoàn.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
