<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class IncidentAction extends Model
{
    public const TYPES = ['created' => 'Mở sự cố', 'assigned' => 'Phân công xử lý', 'note' => 'Ghi nhận xác minh', 'hold_added' => 'Bổ sung khóa', 'proposed' => 'Đề xuất kết luận', 'approved' => 'Duyệt kết luận', 'rejected' => 'Trả lại xác minh', 'finance_posted' => 'Ghi sổ điều chỉnh tài chính', 'finance_reversed' => 'Đảo điều chỉnh tài chính'];

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'proposed_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Không sửa diễn biến sự cố.'));
        static::deleting(fn () => throw new LogicException('Không xóa diễn biến sự cố.'));
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
