<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseTransfer;

class WarehouseTransferPolicy
{
    public function __construct(private LogisticsPolicy $logistics) {}

    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && ($user->hasPermission('logistics.dispatch') || $user->hasPermission('logistics.warehouse'));
    }

    public function view(User $user, WarehouseTransfer $transfer): bool
    {
        return $this->viewAny($user) && ($this->logistics->inWarehouse($user, $transfer->source_warehouse_id) || $this->logistics->inWarehouse($user, $transfer->destination_warehouse_id));
    }

    public function receive(User $user, WarehouseTransfer $transfer): bool
    {
        return $transfer->created_by !== $user->id && $this->logistics->warehouse($user, $transfer->destination_warehouse_id);
    }
}
