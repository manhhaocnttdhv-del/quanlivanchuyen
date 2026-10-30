<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ExportLog extends Model
{
    public const STATUSES = ['queued' => 'Đang chờ', 'processing' => 'Đang tạo tệp', 'ready' => 'Sẵn sàng tải',
        'failed' => 'Không tạo được tệp', 'expired' => 'Đã hết hạn'];

    protected $guarded = ['id'];

    protected $hidden = ['path', 'scope_hash', 'client_token', 'request_hash'];

    protected $casts = ['filters' => 'array', 'user_id' => 'integer', 'row_count' => 'integer', 'generated_at' => 'datetime', 'expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Giữ lịch sử xuất báo cáo. Chỉ dọn tệp hết hạn.'));
        static::updating(function (self $export) {
            if ($export->isDirty(['code', 'user_id', 'report_type', 'format', 'filters', 'scope_hash', 'client_token', 'request_hash'])) {
                throw new LogicException('Không sửa bộ lọc và người yêu cầu xuất tệp.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
