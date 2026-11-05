<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const LABELS = [
        'admin' => 'Admin (kiêm kế toán)',
        'operations' => 'Nhân viên vận hành',
        'shipper' => 'Shipper',
        'shop' => 'Khách hàng gửi hàng',
    ];

    public const INTERNAL_NAMES = ['admin', 'operations', 'shipper'];

    protected $fillable = ['name', 'label'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
