<?php

namespace App\Policies;

use App\Models\DeliveryTask;
use App\Models\User;

class LogisticsPolicy
{
    public function inWarehouse(User $user, ?int $warehouseId): bool
    {
        return $warehouseId !== null && $user->status === 'active' && ($user->hasRole('admin') || $user->warehouses()->whereKey($warehouseId)->exists());
    }

    public function dispatch(User $user, ?int $warehouseId): bool
    {
        return $user->hasPermission('logistics.dispatch') && $this->inWarehouse($user, $warehouseId);
    }

    public function warehouse(User $user, ?int $warehouseId): bool
    {
        return $user->hasPermission('logistics.warehouse') && $this->inWarehouse($user, $warehouseId);
    }

    public function approve(User $user, ?int $warehouseId): bool
    {
        return $user->hasPermission('logistics.approve') && $this->inWarehouse($user, $warehouseId);
    }

    public function shipper(User $user, DeliveryTask $task): bool
    {
        return $user->hasRole('shipper') && $task->shipper_id === $user->id && $this->inWarehouse($user, $task->warehouse_id);
    }

    public function view(User $user, DeliveryTask $task): bool
    {
        return $this->shipper($user, $task) || $this->dispatch($user, $task->warehouse_id) || $this->warehouse($user, $task->warehouse_id);
    }
}
