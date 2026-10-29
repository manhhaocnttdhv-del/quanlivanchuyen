<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;

class ComplaintPolicy
{
    public function __construct(private LogisticsPolicy $logistics) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('complaints.manage') || $user->shop()->exists();
    }

    public function createForOrder(User $user, Order $order): bool
    {
        return $user->status === 'active' && $order->shop->user_id === $user->id && $order->submitted_at !== null && $order->warehouse_id !== null;
    }

    public function manage(User $user, Complaint $complaint): bool
    {
        return $user->hasPermission('complaints.manage') && $this->logistics->inWarehouse($user, $complaint->warehouse_id);
    }

    public function view(User $user, Complaint $complaint): bool
    {
        return $user->status === 'active' && ($complaint->shop->user_id === $user->id || $this->manage($user, $complaint));
    }
}
