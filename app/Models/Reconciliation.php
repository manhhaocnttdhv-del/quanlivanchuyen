<?php

namespace App\Models;

class Reconciliation extends FinanceDocument
{
    public const STATUSES = ['draft' => 'Bản nháp', 'submitted' => 'Chờ duyệt', 'returned' => 'Trả lại', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy'];

    public const RESERVING_STATUSES = ['draft', 'submitted', 'returned', 'approved'];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function bank()
    {
        return $this->belongsTo(ShopBankAccount::class, 'shop_bank_account_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allLines()
    {
        return $this->hasMany(ReconciliationLine::class);
    }

    public function lines()
    {
        // Eager loading khoi tao model rong; so sanh cot thay vi doc $this->line_version.
        return $this->allLines()->whereHas('reconciliation', fn ($q) => $q->whereColumn('reconciliations.line_version', 'reconciliation_lines.version'));
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentAllocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
