<?php

namespace App\Services;

use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Reconciliation;
use App\Models\User;
use App\Models\WarehouseTransfer;
use App\Policies\FinancePolicy;
use App\Policies\IncidentPolicy;
use App\Policies\LogisticsPolicy;
use App\Policies\OrderPolicy;
use App\Policies\WarehouseTransferPolicy;

class NotificationTarget
{
    public const MODELS = ['order' => Order::class, 'task' => DeliveryTask::class, 'transfer' => WarehouseTransfer::class, 'incident' => Incident::class, 'reconciliation' => Reconciliation::class];

    public function find(string $type, int $id)
    {
        $model = self::MODELS[$type] ?? null;

        return $model ? $model::find($id) : null;
    }

    public function url(User $user, $target): ?string
    {
        if ($user->status !== 'active' || ! $target) {
            return null;
        }

        return match (true) {
            $target instanceof Order => app(OrderPolicy::class)->view($user, $target) ? route('orders.show', $target) : null,
            $target instanceof DeliveryTask => app(LogisticsPolicy::class)->view($user, $target) ? route('logistics.show', $target) : null,
            $target instanceof WarehouseTransfer => app(WarehouseTransferPolicy::class)->view($user, $target) ? route('transfers.show', $target) : null,
            $target instanceof Incident => app(IncidentPolicy::class)->view($user, $target) ? route('incidents.show', $target) : null,
            $target instanceof Reconciliation => app(FinancePolicy::class)->view($user, $target) ? route('finance.reconciliations.show', $target) : null,
            default => null,
        };
    }
}
