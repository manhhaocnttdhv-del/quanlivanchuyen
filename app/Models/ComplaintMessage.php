<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ComplaintMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_internal' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Trao đổi chỉ được ghi thêm.'));
        static::deleting(fn () => throw new LogicException('Không xóa trao đổi khiếu nại.'));
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
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
