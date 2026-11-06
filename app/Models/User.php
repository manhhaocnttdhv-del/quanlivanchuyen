<?php

namespace App\Models;

use App\Support\Money;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'auth_version' => 'integer',
        'password' => 'hashed',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(BusinessNotification::class, 'notifiable')->latest('created_at')->latest('id');
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user');
    }

    public function requiredShipperWarehouseIds(): Collection
    {
        $cashWarehouses = DeliveryCollection::where('shipper_id', $this->id)->select(['id', 'warehouse_id', 'amount'])
            ->withSum('depositAllocations as deposited_amount', 'amount')
            ->withSum(['adjustmentLines as correction_amount' => fn ($q) => $q->whereHas('adjustment', fn ($q) => $q->where('status', 'approved'))], 'amount')
            ->get()->filter(fn ($collection) => Money::value($collection->amount) + Money::value($collection->correction_amount ?? 0)
                > Money::value($collection->deposited_amount ?? 0))->pluck('warehouse_id');

        return DeliveryTask::where('shipper_id', $this->id)->whereIn('status', DeliveryTask::ACTIVE)->pluck('warehouse_id')
            ->merge(WarehouseItem::where('holder_type', 'shipper')->where('holder_user_id', $this->id)->pluck('warehouse_id'))
            ->merge($cashWarehouses)->unique();
    }

    public function shop()
    {
        return $this->hasOne(Shop::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    public function workspaceRoute(): string
    {
        if ($this->hasRole('admin')) {
            return 'dashboard';
        }
        if ($this->hasPermission('logistics.dispatch') || $this->hasPermission('logistics.warehouse')) {
            return 'logistics.index';
        }
        if ($this->hasRole('shipper')) {
            return 'delivery.mine';
        }
        if ($this->shop) {
            return $this->shop->status === 'approved' ? 'orders.index' : 'shop.profile';
        }

        return 'dashboard';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'active' && $this->roles()->whereHas('permissions', fn ($query) => $query->where('name', $permission))->exists();
    }
}
