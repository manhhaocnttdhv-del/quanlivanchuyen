<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class FinanceDocument extends Model
{
    protected $guarded = ['id', 'active_incident_id', 'active_reversal_id'];

    protected $hidden = ['client_token', 'request_hash', 'bank_snapshot'];

    protected $casts = ['revision' => 'integer', 'line_version' => 'integer', 'amount' => 'decimal:2', 'requested_amount' => 'decimal:2', 'received_amount' => 'decimal:2',
        'cod_total' => 'decimal:2', 'fee_total' => 'decimal:2', 'adjustment_total' => 'decimal:2', 'payable_amount' => 'decimal:2', 'debt_amount' => 'decimal:2',
        'bank_snapshot' => 'encrypted:array', 'period_start' => 'date', 'period_end' => 'date', 'received_at' => 'datetime', 'paid_at' => 'datetime', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Không xóa chứng từ tài chính.'));
        static::updating(function (self $document) {
            if ($document->isDirty(['code', 'created_by', 'warehouse_id', 'client_token', 'request_hash', 'reason', 'amount', 'shipper_id', 'shop_id', 'order_id', 'incident_id', 'kind', 'reverses_adjustment_id'])) {
                throw new LogicException('Không sửa căn cứ gốc của chứng từ.');
            }
            $bankReview = $document instanceof Reconciliation && $document->getRawOriginal('status') === 'approved' && $document->status === 'returned';
            $allowed = $bankReview ? ['status', 'revision', 'updated_at'] : ['revision', 'updated_at'];
            if (in_array($document->getRawOriginal('status'), ['approved', 'confirmed', 'succeeded', 'failed', 'rejected', 'cancelled']) && count(array_diff(array_keys($document->getDirty()), $allowed)) > 0) {
                throw new LogicException('Chứng từ đã ghi sổ không được sửa; lập chứng từ điều chỉnh.');
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function events()
    {
        return $this->hasMany(FinanceEvent::class, 'document_id')->where('document_type', $this->getTable())->orderByDesc('id');
    }

    public function attachments()
    {
        return $this->hasMany(FinanceAttachment::class, $this->getForeignKey());
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->status === 'active' && $user->hasRole('admin')) {
            return;
        }
        $query->where(function ($q) use ($user) {
            $q->whereRaw('1 = 0');
            if ($user->status !== 'active') {
                return;
            }
            if ($user->hasPermission('finance.manage')) {
                $q->orWhereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
            }
            if ($this instanceof CashDeposit && $user->hasRole('shipper')) {
                $q->orWhere('shipper_id', $user->id);
            } elseif ($this instanceof Reconciliation) {
                $q->orWhere(fn ($q) => $q->whereIn('status', ['submitted', 'approved'])->whereHas('shop', fn ($q) => $q->where('user_id', $user->id)));
            } elseif ($this instanceof Payment) {
                $q->orWhereHas('reconciliation.shop', fn ($q) => $q->where('user_id', $user->id));
            } elseif ($this instanceof FeeReceipt) {
                $q->orWhereHas('shop', fn ($q) => $q->where('user_id', $user->id));
            }
        });
    }
}
