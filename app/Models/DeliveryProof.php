<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryProof extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['evidence_path', 'content_sha256'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bằng chứng đã ghi nhận không được sửa.'));
        static::deleting(fn () => throw new LogicException('Không xóa bằng chứng giao hàng.'));
    }
}
