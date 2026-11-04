<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderStatusHistory extends Model
{
    public const EVENTS = ['created' => 'Tạo bản nháp', 'updated' => 'Cập nhật thông tin', 'submitted' => 'Gửi duyệt', 'reviewed' => 'Xét duyệt', 'cancelled' => 'Hủy đơn', 'change_requested' => 'Đề nghị thay đổi', 'change_rejected' => 'Từ chối đề nghị', 'change_applied' => 'Chấp nhận đề nghị'];

    public $timestamps = false;

    protected $guarded = ['id'];

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
