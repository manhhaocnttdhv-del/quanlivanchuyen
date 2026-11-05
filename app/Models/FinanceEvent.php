<?php

namespace App\Models;

class FinanceEvent extends FinanceLine
{
    public const LABELS = [
        'deposit_submitted' => 'Gửi đề nghị nộp tiền', 'deposit_confirmed' => 'Xác nhận tiền về quỹ', 'deposit_rejected' => 'Từ chối nộp tiền', 'deposit_allocated' => 'Phân bổ tiền còn dư',
        'reconciliation_created' => 'Lập đối soát', 'reconciliation_replace' => 'Cập nhật nguồn đối soát', 'reconciliation_submit' => 'Gửi duyệt đối soát', 'reconciliation_approve' => 'Duyệt đối soát',
        'reconciliation_return' => 'Trả lại đối soát', 'reconciliation_reject' => 'Từ chối đối soát', 'reconciliation_cancel' => 'Hủy bản nháp', 'reconciliation_reopen' => 'Mở duyệt lại ngân hàng', 'reconciliation_refresh-bank' => 'Cập nhật ngân hàng chờ duyệt',
        'payment_succeeded' => 'Thanh toán shop thành công', 'payment_failed' => 'Thanh toán shop thất bại', 'fee_received' => 'Thu nợ phí',
        'adjustment_submitted' => 'Gửi điều chỉnh', 'adjustment_approve' => 'Duyệt và ghi sổ điều chỉnh', 'adjustment_reject' => 'Từ chối điều chỉnh',
    ];

    protected $casts = ['payload' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
