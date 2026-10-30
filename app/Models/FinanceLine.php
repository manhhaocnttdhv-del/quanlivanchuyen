<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class FinanceLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Dòng tài chính chỉ được ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Không xóa dòng tài chính.'));
    }
}
