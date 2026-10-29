<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class Attachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'content_sha256'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Không sửa bằng chứng.'));
        static::deleting(fn () => throw new LogicException('Không xóa bằng chứng.'));
    }

    public function incidentAction()
    {
        return $this->belongsTo(IncidentAction::class);
    }

    public function complaintMessage()
    {
        return $this->belongsTo(ComplaintMessage::class);
    }
}
