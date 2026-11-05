<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderStatusHistory extends Model
{
    public const EVENTS = ['created' => 'Tạo bản nháp', 'updated' => 'Cập nhật thông tin', 'submitted' => 'Gửi duyệt', 'reviewed' => 'Xét duyệt', 'cancelled' => 'Hủy đơn', 'change_requested' => 'Đề nghị thay đổi', 'change_rejected' => 'Từ chối đề nghị', 'change_applied' => 'Chấp nhận đề nghị'];

    public $timestamps = false;

    public const LOGISTICS_EVENTS = ['task_assigned' => 'Phân công nhiệm vụ', 'pickup_confirmed' => 'Xác nhận lấy hàng', 'pickup_failed' => 'Lấy hàng thất bại', 'task_recalled' => 'Thu hồi nhiệm vụ', 'return_authorized' => 'Quyết định hoàn', 'handover_prepared' => 'Lập phiếu bàn giao', 'handover_confirmed' => 'Xác nhận bàn giao', 'hold_released' => 'Xử lý sai lệch / bỏ giữ', 'stocktake_difference' => 'Sai lệch kiểm kê'];

    protected $guarded = ['id'];

    public const WAREHOUSE_EVENTS = ['weight_review_requested' => 'Đề nghị tính cước theo cân thực tế', 'transfer_dispatched' => 'Xuất chuyển kho', 'transfer_partial' => 'Kho nhận kiểm đếm thiếu kiện', 'transfer_received' => 'Kho nhận đã nhập đủ kiện', 'delivery_succeeded' => 'Giao hàng thành công', 'delivery_failed' => 'Giao hàng thất bại', 'return_succeeded' => 'Hoàn hàng thành công', 'return_failed' => 'Hoàn hàng thất bại', 'retry_authorized' => 'Cho phép giao lại', 'incident_opened' => 'Ghi nhận sự cố', 'incident_updated' => 'Cập nhật xử lý sự cố'];

    protected $casts = ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lịch sử chỉ được ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Không được xóa lịch sử vận đơn.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
