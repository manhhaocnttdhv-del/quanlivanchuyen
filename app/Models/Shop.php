<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    public const ACCOUNT_TYPES = ['individual' => 'Cá nhân', 'business' => 'Cửa hàng'];

    public const STATUSES = ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'suspended' => 'Tạm ngừng'];

    protected $fillable = ['name', 'area_id', 'address'];

    protected $casts = ['approved_at' => 'datetime'];

    public function isIndividual(): bool
    {
        return $this->account_type === 'individual';
    }

    public function getAccountTypeLabelAttribute(): string
    {
        return self::ACCOUNT_TYPES[$this->account_type] ?? self::ACCOUNT_TYPES['business'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function addresses()
    {
        return $this->hasMany(PickupAddress::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(ShopBankAccount::class);
    }

    public function currentBankAccount()
    {
        return $this->belongsTo(ShopBankAccount::class, 'current_bank_account_id');
    }

    public function scopeReviewableBy(Builder $query, User $user): void
    {
        if ($user->hasRole('admin') || $user->hasPermission('banks.verify')) {
            return;
        }
        $query->whereIn('area_id', $user->warehouses()->select('warehouses.area_id'));
    }
}
