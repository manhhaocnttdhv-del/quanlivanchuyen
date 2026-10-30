<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeliveryCollection extends Model
{
    public const TYPES = ['cod' => 'COD thu hộ', 'shipping_fee' => 'Cước vận chuyển', 'return_fee' => 'Phí hoàn thực thu'];

    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key'];

    protected $casts = ['expected_amount' => 'decimal:2', 'amount' => 'decimal:2', 'collected_at' => 'datetime'];

    protected static function booted(): void
    {
        // Sprint 06 phân bổ/nộp tiền bằng chứng từ riêng, không sửa khoản thu gốc.
        static::updating(fn () => throw new LogicException('Khoản thu gốc không được sửa.'));
        static::deleting(fn () => throw new LogicException('Không xóa khoản thu gốc.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function attempt()
    {
        return $this->belongsTo(DeliveryAttempt::class, 'delivery_attempt_id');
    }

    public function shipper()
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }

    public function depositAllocations()
    {
        return $this->hasMany(DepositAllocation::class);
    }

    public function adjustmentLines()
    {
        return $this->hasMany(AdjustmentLine::class);
    }

    public function reconciliationLines()
    {
        return $this->hasMany(ReconciliationLine::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        $query->where(function ($q) use ($user) {
            $q->where('shipper_id', $user->id);
            if ($user->hasPermission('delivery.collections.view')) {
                $q->orWhereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
            }
            $q->orWhereHas('order.shop', fn ($q) => $q->where('user_id', $user->id));
        });
    }
}
