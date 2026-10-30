<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === 'active' && $user->hasRole('admin') && $user->hasPermission('audit.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->viewAny($user);
    }
}
