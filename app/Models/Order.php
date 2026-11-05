<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const STATUSES = ['draft' => 'Bản nháp', 'submitted' => 'Chờ duyệt', 'needs_info' => 'Cần bổ sung', 'rejected' => 'Từ chối', 'approved' => 'Đã duyệt', 'cancelled' => 'Đã hủy', 'picked_up' => 'Đã lấy hàng', 'at_warehouse' => 'Tại kho', 'out_for_delivery' => 'Đang giao', 'delivered' => 'Đã giao', 'delivery_failed' => 'Giao thất bại', 'returning' => 'Đang hoàn', 'returned' => 'Đã hoàn', 'return_failed' => 'Hoàn thất bại'];

    public const EDITABLE = ['draft', 'needs_info', 'rejected'];

    public const INPUT_FIELDS = ['pickup_address_id', 'warehouse_id', 'area_id', 'recipient_name', 'recipient_phone', 'recipient_address', 'weight_grams', 'parcel_count', 'service', 'cod_amount', 'fee_payer', 'note'];

    protected $guarded = ['id'];

    protected $casts = ['cod_amount' => 'decimal:2', 'shipping_fee' => 'decimal:2', 'recipient_total' => 'decimal:2', 'weight_kg' => 'decimal:3', 'sender_snapshot' => 'array', 'recipient_snapshot' => 'array', 'fee_snapshot' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'delivered_at' => 'datetime', 'returned_at' => 'datetime', 'financial_hold' => 'boolean', 'delivery_attempt_count' => 'integer', 'return_attempt_count' => 'integer', 'delivery_attempt_limit' => 'integer', 'retry_authorized_attempt' => 'integer'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function shipper()
    {
        return $this->belongsTo(User::class, 'assigned_shipper_id');
    }

    public function pickupAddress()
    {
        return $this->belongsTo(PickupAddress::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tasks()
    {
        return $this->hasMany(DeliveryTask::class);
    }

    public function parcels()
    {
        return $this->hasMany(WarehouseItem::class)->orderBy('parcel_number');
    }

    public function deliveryAttempts()
    {
        return $this->hasMany(DeliveryAttempt::class)->latest('id');
    }

    public function deliveryDecisions()
    {
        return $this->hasMany(DeliveryDecision::class)->latest('id');
    }

    public function returnRecord()
    {
        return $this->hasOne(ReturnRecord::class);
    }

    public function collections()
    {
        return $this->hasMany(DeliveryCollection::class);
    }

    public function discrepancies()
    {
        return $this->hasMany(Discrepancy::class);
    }

    public function histories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('id');
    }

    public function changeRequests()
    {
        return $this->hasMany(OrderChangeRequest::class)->orderByDesc('id');
    }

    public function pendingChange()
    {
        return $this->hasOne(OrderChangeRequest::class)->where('status', 'pending');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        $query->where(function ($visible) use ($user) {
            $visible->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
            if ($user->hasPermission('orders.review')) {
                $visible->orWhere(fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'))->where('status', '!=', 'draft'));
            }
        });
    }

    public function scopeFiltered(Builder $query, array $filters): void
    {
        $query->when($filters['q'] ?? null, fn ($q, $v) => $q->where('tracking_code', 'like', $v.'%'));
        foreach (['status', 'shop_id', 'area_id', 'assigned_shipper_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        }
        $query->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, 'Asia/Ho_Chi_Minh')->startOfDay()->utc()))
            ->when($filters['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v, 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->utc()));
    }

    public function inputData(): array
    {
        return array_merge($this->only(self::INPUT_FIELDS), ['cod_amount' => (int) $this->cod_amount, 'items' => $this->items->map->only(['name', 'quantity'])->all()]);
    }
}
