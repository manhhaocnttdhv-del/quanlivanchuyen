<?php

namespace App\Models;

class Adjustment extends FinanceDocument
{
    public const STATUSES = ['submitted' => 'Chờ duyệt', 'approved' => 'Đã ghi sổ', 'rejected' => 'Đã từ chối'];

    public const KINDS = ['compensation' => 'Bồi thường shop', 'fee_reduction' => 'Giảm phí shop', 'collection_increase' => 'Tăng thực thu cần nộp', 'collection_decrease' => 'Giảm thực thu cần nộp', 'collection_verification' => 'Chấp nhận thực thu đã xác minh', 'reversal' => 'Đảo điều chỉnh gốc'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function original()
    {
        return $this->belongsTo(self::class, 'reverses_adjustment_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_adjustment_id')->where('status', 'approved');
    }

    public function line()
    {
        return $this->hasOne(AdjustmentLine::class);
    }

    public function reservedLines()
    {
        return $this->hasMany(ReconciliationLine::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
