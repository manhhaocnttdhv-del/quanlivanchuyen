<?php

namespace App\Policies;

use App\Models\Adjustment;
use App\Models\CashDeposit;
use App\Models\FeeReceipt;
use App\Models\FinanceDocument;
use App\Models\Payment;
use App\Models\Reconciliation;
use App\Models\User;

class FinancePolicy
{
    public function __construct(private LogisticsPolicy $scope) {}

    public function staff(User $user, int $warehouse): bool
    {
        return $user->hasPermission('finance.manage') && $this->scope->inWarehouse($user, $warehouse);
    }

    public function any(User $user): bool
    {
        return $user->status === 'active' && ($user->hasRole('shipper') || $user->shop !== null || $user->hasPermission('finance.manage'));
    }

    public function view(User $user, FinanceDocument $document): bool
    {
        if ($this->staff($user, $document->warehouse_id)) {
            return true;
        }
        if ($document instanceof CashDeposit) {
            return $user->status === 'active' && $document->shipper_id === $user->id;
        }
        $shop = match (true) {
            $document instanceof Reconciliation, $document instanceof FeeReceipt => $document->shop,
            $document instanceof Payment => $document->reconciliation->shop,
            default => null,
        };

        return $user->status === 'active' && $shop?->user_id === $user->id && (! $document instanceof Reconciliation || in_array($document->status, ['submitted', 'approved']));
    }

    public function deposit(User $user, int $warehouse, int $shipper): bool
    {
        return $this->staff($user, $warehouse) || ($user->hasRole('shipper') && $user->id === $shipper && $this->scope->inWarehouse($user, $warehouse));
    }

    public function approve(User $user, FinanceDocument $document): bool
    {
        return $user->hasPermission('finance.approve') && $this->scope->inWarehouse($user, $document->warehouse_id) && $user->id !== $document->created_by &&
            (! $document instanceof CashDeposit || $user->id !== $document->shipper_id) &&
            (! $document instanceof Adjustment || $user->id !== ($document->incident ?? $document->original?->incident)?->created_by);
    }
}
