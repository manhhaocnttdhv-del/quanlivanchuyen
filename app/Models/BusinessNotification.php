<?php

namespace App\Models;

use Illuminate\Notifications\DatabaseNotification;
use LogicException;

class BusinessNotification extends DatabaseNotification
{
    protected $table = 'notifications';

    protected static function booted(): void
    {
        static::updating(function (self $notification) {
            if ($notification->isDirty(['type', 'notifiable_type', 'notifiable_id', 'audit_log_id', 'data'])) {
                throw new LogicException('Không sửa nội dung và người nhận thông báo.');
            }
        });
        static::deleting(fn () => throw new LogicException('Giữ lịch sử thông báo; chỉ đổi trạng thái đã đọc.'));
    }
}
