<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDispatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['queued_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function auditLog()
    {
        return $this->belongsTo(AuditLog::class);
    }
}
