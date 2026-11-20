<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WarehouseTransfer extends Model
{
    public const STATUSES = ['in_transit' => 'Đang chuyển', 'partial' => 'Nhận một phần', 'received' => 'Đã nhận đủ', 'closed_loss' => 'Kết thúc chuyến có mất kiện'];

    protected $guarded = ['id', 'active_order_id'];

    protected $casts = ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function source()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destination()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(WarehouseTransferLine::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasRole('admin')) {
            $ids = $user->warehouses()->pluck('warehouses.id');
            $query->where(fn ($q) => $q->whereIn('source_warehouse_id', $ids)->orWhereIn('destination_warehouse_id', $ids));
        }
    }
}
