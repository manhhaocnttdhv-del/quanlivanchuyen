<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Incident extends Model
{
    public const TYPES = ['lost' => 'Mất / thiếu hàng', 'damaged' => 'Hàng hư hỏng', 'money' => 'Sai lệch tiền', 'information' => 'Sai thông tin', 'other' => 'Sự cố khác'];

    public const SEVERITIES = ['normal' => 'Thông thường', 'high' => 'Nghiêm trọng', 'critical' => 'Khẩn cấp'];

    public const STATUSES = ['open' => 'Đang xác minh', 'in_review' => 'Chờ duyệt kết luận', 'closed' => 'Đã kết luận'];

    public const OUTCOMES = ['no_financial' => 'Không điều chỉnh tiền', 'compensation' => 'Đề xuất bồi thường', 'fee_reduction' => 'Đề xuất giảm phí', 'financial_correction' => 'Đề xuất điều chỉnh tài chính'];

    protected $guarded = ['id'];

    protected $hidden = ['client_token', 'creation_hash'];

    protected $casts = ['blocks_dispatch' => 'boolean', 'blocks_reconciliation' => 'boolean', 'revision' => 'integer', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Không xóa hồ sơ sự cố.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
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

    public function actions()
    {
        return $this->hasMany(IncidentAction::class)->orderByDesc('id');
    }

    public function proposal()
    {
        return $this->belongsTo(IncidentAction::class, 'proposal_action_id');
    }

    public function approvedAction()
    {
        return $this->belongsTo(IncidentAction::class, 'approved_action_id');
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole('admin')) {
            return;
        }
        $query->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
        if (! $user->hasPermission('incidents.manage') && ! $user->hasPermission('incidents.approve')) {
            $query->where('created_by', $user->id);
        }
    }
}
