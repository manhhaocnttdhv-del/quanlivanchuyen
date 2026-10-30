<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DeliveryCollection;
use App\Models\User;
use App\Policies\ComplaintPolicy;
use App\Policies\FinancePolicy;
use App\Policies\ReportPolicy;

class AuditSubject
{
    public const LABELS = [
        'Order' => 'Vận đơn', 'OrderChangeRequest' => 'Đề nghị sửa đơn', 'User' => 'Tài khoản',
        'Shop' => 'Shop', 'ShopBankAccount' => 'Ngân hàng shop', 'PickupAddress' => 'Địa chỉ lấy hàng',
        'Area' => 'Khu vực', 'Warehouse' => 'Kho', 'FeeVersion' => 'Biểu phí', 'WarehouseLocation' => 'Vị trí kho',
        'Stocktake' => 'Kiểm kê', 'Discrepancy' => 'Sai lệch', 'DeliveryAttempt' => 'Lần giao / hoàn',
        'DeliveryCollection' => 'Khoản thu', 'ReturnRecord' => 'Hồ sơ hoàn', 'Incident' => 'Sự cố',
        'Complaint' => 'Khiếu nại', 'CashDeposit' => 'Nộp quỹ', 'Reconciliation' => 'Đối soát',
        'Payment' => 'Chi shop', 'FeeReceipt' => 'Thu phí', 'Receivable' => 'Nghĩa vụ phí',
        'Adjustment' => 'Điều chỉnh', 'ExportLog' => 'Xuất báo cáo',
    ];

    public function url(User $user, AuditLog $log): ?string
    {
        $type = class_basename($log->subject_type);
        // Chi tao model tu danh sach co dinh, khong khoi tao class tuy y tu request/database.
        if (! isset(self::LABELS[$type]) || $log->subject_type !== 'App\\Models\\'.$type) {
            return null;
        }
        $target = $log->subject_type::find($log->subject_id);
        if (! $target) {
            return null;
        }
        $notifications = app(NotificationTarget::class);
        if (in_array($type, ['Order', 'Incident', 'Reconciliation'])) {
            return $notifications->url($user, $target);
        }
        if (in_array($type, ['OrderChangeRequest', 'DeliveryAttempt', 'ReturnRecord', 'Discrepancy'])) {
            return $notifications->url($user, $target->order);
        }
        if (in_array($type, ['CashDeposit', 'Adjustment', 'FeeReceipt', 'Payment'])) {
            if (! app(FinancePolicy::class)->view($user, $target)) {
                return null;
            }

            return match ($type) {
                'CashDeposit' => route('finance.deposits.show', $target),
                'Adjustment' => route('finance.adjustments.show', $target),
                'FeeReceipt' => route('finance.receipts.show', $target),
                'Payment' => $notifications->url($user, $target->reconciliation),
            };
        }
        if ($type === 'DeliveryCollection' && DeliveryCollection::visibleTo($user)->whereKey($target->id)->exists()) {
            return route('finance.collections.show', $target);
        }
        if ($type === 'Receivable' && app(FinanceQueries::class)->fees($user, [])->whereKey($target->id)->exists()) {
            return route('finance.fees.show', $target);
        }
        if ($type === 'Complaint' && app(ComplaintPolicy::class)->view($user, $target)) {
            return route('complaints.show', $target);
        }
        if ($type === 'ExportLog' && app(ReportPolicy::class)->export($user, $target)) {
            return route('reports.export', $target);
        }
        if ($type === 'Shop' && $user->can('view', $target)) {
            return route('shops.show', $target);
        }

        return null;
    }
}
