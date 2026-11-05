<?php

namespace App\Policies;

use App\Models\BusinessNotification;
use App\Models\User;

class BusinessNotificationPolicy
{
    public function view(User $user, BusinessNotification $notification): bool
    {
        return $user->status === 'active' && $notification->notifiable_type === $user->getMorphClass() && (int) $notification->notifiable_id === $user->id;
    }

    public function update(User $user, BusinessNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
