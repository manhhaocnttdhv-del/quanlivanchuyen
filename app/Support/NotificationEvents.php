<?php

namespace App\Support;

use App\Models\AuditLog;

class NotificationEvents
{
    public const CATEGORIES = ['order' => 'Vận đơn', 'task' => 'Nhiệm vụ', 'delivery' => 'Giao hàng', 'transfer' => 'Chuyển kho', 'incident' => 'Sự cố', 'finance' => 'Đối soát'];

    public const EVENTS = [
        'order.submitted' => ['order', 'Vận đơn mới chờ duyệt'],
        'order.reviewed' => ['order', 'Vận đơn đã được xét duyệt'],
        'logistics.task_assigned' => ['task', 'Bạn có nhiệm vụ mới'],
        'logistics.transfer_dispatched' => ['transfer', 'Có chuyến chuyển kho chờ nhận'],
        'logistics.transfer_partial' => ['transfer', 'Chuyển kho chưa nhận đủ kiện'],
        'logistics.transfer_received' => ['transfer', 'Kho đích đã nhận đủ kiện'],
        'logistics.transfer_closed_loss' => ['transfer', 'Kết thúc chuyển kho, có kiện đã xác nhận mất'],
        'delivery.attempt_recorded' => ['delivery', 'Giao hoặc hoàn hàng thất bại'],
        'incident.created' => ['incident', 'Có sự cố cần xử lý'],
        'finance.reconciliation_submit' => ['finance', 'Đối soát chờ duyệt'],
        'finance.reconciliation_approve' => ['finance', 'Đối soát đã được duyệt'],
        'finance.reconciliation_return' => ['finance', 'Đối soát được trả lại'],
        'finance.reconciliation_reject' => ['finance', 'Đối soát bị từ chối'],
    ];

    public static function supports(AuditLog $log): bool
    {
        return isset(self::EVENTS[$log->event]) && ($log->event !== 'delivery.attempt_recorded' || ($log->new_values['outcome'] ?? null) === 'failed');
    }
}
