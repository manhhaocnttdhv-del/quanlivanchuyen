<?php

namespace App\Policies;

use App\Models\ExportLog;
use App\Models\User;

class ReportPolicy
{
    public function view(User $user, string $type): bool
    {
        if ($user->status !== 'active' || ! in_array($type, ['operations', 'finance'])) {
            return false;
        }

        return $user->hasRole('admin') || $user->shop !== null || $this->staff($user, $type);
    }

    public function any(User $user): bool
    {
        return $this->view($user, 'operations') || $this->view($user, 'finance');
    }

    public function staff(User $user, string $type): bool
    {
        return $user->hasRole('admin') || ($user->hasPermission('reports.'.$type) && ($type !== 'finance' || $user->hasPermission('finance.manage')));
    }

    public function export(User $user, ExportLog $export): bool
    {
        return $export->user_id === $user->id && $this->view($user, $export->report_type);
    }
}
