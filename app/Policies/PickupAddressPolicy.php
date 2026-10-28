<?php

namespace App\Policies;

use App\Models\PickupAddress;
use App\Models\User;

class PickupAddressPolicy
{
    public function update(User $user, PickupAddress $address): bool
    {
        return $address->shop->user_id === $user->id;
    }
}
