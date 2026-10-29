<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Complaint extends Model
{
    public const TYPES = ['damaged' => 'Hàng hư hỏng', 'lost' => 'Mất / thiếu hàng', 'money' => 'Sai lệch tiền thu', 'delay' => 'Chậm giao hàng', 'information' => 'Thông tin vận đơn', 'other' => 'Nội dung khác'];

    public const STATUSES = ['open' => 'Chờ tiếp nhận', 'in_progress' => 'Đang xử lý', 'closed' => 'Đã giải quyết'];

    public const NEEDS_INCIDENT = ['damaged', 'lost', 'money'];

    protected $guarded = ['id'];

    protected $hidden = ['client_token', 'creation_hash'];

    protected $casts = ['due_at' => 'datetime', 'first_response_at' => 'datetime', 'closed_at' => 'datetime', 'revision' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Không xóa hồ sơ khiếu nại.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function messages()
    {
        return $this->hasMany(ComplaintMessage::class)->orderByDesc('id');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        $query->where(function ($q) use ($user) {
            $q->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
            if ($user->hasPermission('complaints.manage')) {
                $q->orWhereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
            }
        });
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', '!=', 'closed')->where('due_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'closed' && $this->due_at->isPast();
    }
}
