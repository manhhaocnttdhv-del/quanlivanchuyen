<?php

namespace App\Policies;

use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;

class IncidentPolicy
{
    public function __construct(private LogisticsPolicy $logistics) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('incidents.manage') || $user->hasPermission('incidents.approve') || $user->hasRole('shipper');
    }

    public function createForOrder(User $user, Order $order): bool
    {
        return $order->status !== 'draft' && $this->logistics->inWarehouse($user, $order->warehouse_id) &&
            ($user->hasPermission('incidents.manage') || ($user->hasRole('shipper') &&
                $order->tasks()->where('shipper_id', $user->id)->whereIn('status', DeliveryTask::ACTIVE)->exists()));
    }

    public function view(User $user, Incident $incident): bool
    {
        return $this->logistics->inWarehouse($user, $incident->warehouse_id) && ($user->hasPermission('incidents.manage') || $user->hasPermission('incidents.approve') || ($user->hasRole('shipper') && $incident->created_by === $user->id));
    }

    public function manage(User $user, Incident $incident): bool
    {
        return $user->hasPermission('incidents.manage') && $this->logistics->inWarehouse($user, $incident->warehouse_id);
    }

    public function approve(User $user, Incident $incident): bool
    {
        return $user->hasPermission('incidents.approve') && $this->logistics->inWarehouse($user, $incident->warehouse_id);
    }
}
