<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasPermission('orders.review') || $user->shop()->exists();
    }

    public function create(User $user): bool
    {
        return $user->shop()->exists();
    }

    public function view(User $user, Order $order): bool
    {
        return $user->hasRole('admin') || $order->shop->user_id === $user->id ||
            ($order->status !== 'draft' && $this->review($user, $order));
    }

    public function update(User $user, Order $order): bool
    {
        return $order->shop->user_id === $user->id;
    }

    public function review(User $user, Order $order): bool
    {
        return $user->hasPermission('orders.review') && ($user->hasRole('admin') ||
            $user->warehouses()->where('warehouses.id', $order->warehouse_id)->exists());
    }

    public function print(User $user, Order $order): bool
    {
        return $this->view($user, $order) && ! in_array($order->status, ['draft', 'cancelled', 'rejected', 'needs_info']);
    }
}
