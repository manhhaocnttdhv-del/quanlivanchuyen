<?php

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

class ShopPolicy
{
    public function view(User $user, Shop $shop): bool
    {
        return $user->id === $shop->user_id || $user->hasPermission('shops.review') || $user->hasPermission('banks.verify');
    }

    public function update(User $user, Shop $shop): bool
    {
        return $user->id === $shop->user_id && $user->status === 'active';
    }

    public function review(User $user, Shop $shop): bool
    {
        return $user->id !== $shop->user_id && $user->hasPermission('shops.review');
    }
}
