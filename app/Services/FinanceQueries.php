<?php

namespace App\Services;

use App\Models\DeliveryCollection;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Carbon\Carbon;

class FinanceQueries
{
    public function warehouses(User $user)
    {
        return Warehouse::when(! $user->hasRole('admin'), fn ($q) => $q->whereIn('id', $user->warehouses()->select('warehouses.id')))->orderBy('name')->pluck('name', 'id');
    }

    public function shops(User $user)
    {
        return Shop::whereHas('orders', fn ($q) => $q->when(! $user->hasRole('admin'), fn ($q) => $q->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'))))
            ->orderBy('name')->pluck('name', 'id');
    }

    public function shippers(User $user, ?int $warehouse = null)
    {
        return User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('name', 'shipper'))
            ->whereHas('warehouses', fn ($q) => $q->when($warehouse, fn ($q) => $q->whereKey($warehouse))
                ->when(! $user->hasRole('admin'), fn ($q) => $q->whereIn('warehouses.id', $user->warehouses()->select('warehouses.id'))))
            ->when(! $user->hasPermission('finance.manage'), fn ($q) => $q->whereKey($user->id))->orderBy('name')->pluck('name', 'id');
    }

    public function collections(User $user, array $filters, ?int $except = null)
    {
        return $this->filter(DeliveryCollection::visibleTo($user), $filters, 'collected_at')
            ->with(['order.shop', 'shipper'])
            ->withSum('depositAllocations as deposited_amount', 'amount')
            ->withSum(['adjustmentLines as correction_amount' => fn ($q) => $q->whereHas('adjustment', fn ($q) => $q->where('status', 'approved'))], 'amount')
            ->withSum(['reconciliationLines as reserved_amount' => fn ($q) => $this->reserved($q, $except)], 'amount')
            ->when($filters['shop_id'] ?? null, fn ($q, $id) => $q->whereHas('order', fn ($q) => $q->where('shop_id', $id)))
            ->when($filters['shipper_id'] ?? null, fn ($q, $id) => $q->where('shipper_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
    }

    public function fees(User $user, array $filters, ?int $except = null)
    {
        $query = Receivable::query();
        if (! $user->hasRole('admin')) {
            $user->hasPermission('finance.manage')
                ? $query->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'))
                : $query->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
        }

        return $this->filter($query, $filters, 'accrued_at')->with(['shop', 'order', 'reconciliation'])
            ->when($filters['shop_id'] ?? null, fn ($q, $id) => $q->where('shop_id', $id))
            ->withSum('receiptAllocations as received_amount', 'amount')
            ->withSum(['adjustmentLines as correction_amount' => fn ($q) => $q->whereHas('adjustment', fn ($q) => $q->where('status', 'approved'))], 'amount')
            ->withSum(['reconciliationLines as reserved_amount' => fn ($q) => $this->reserved($q, $except)], 'amount');
    }

    public function effective($source): int
    {
        return Money::value($source->amount) + Money::value($source->correction_amount ?? 0);
    }

    public function held($source): int
    {
        return $this->effective($source) - Money::value($source->deposited_amount ?? 0);
    }

    public function available($source): int
    {
        $amount = $source instanceof DeliveryCollection ? min($this->effective($source), Money::value($source->deposited_amount ?? 0)) : $this->effective($source) - Money::value($source->received_amount ?? 0);

        return $amount - Money::value($source->reserved_amount ?? 0);
    }

    public function filter($query, array $filters, string $date = 'created_at')
    {
        // Du lieu hien tai ghi theo timezone ung dung; khong tu coi DATETIME cu la UTC.
        return $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $value.'%')))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->where($date, '>=', Carbon::parse($value, 'Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')))
            ->when($filters['until'] ?? null, fn ($q, $value) => $q->where($date, '<', Carbon::parse($value, 'Asia/Ho_Chi_Minh')->startOfDay()->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')));
    }

    private function reserved($query, ?int $except): void
    {
        $query->whereHas('reconciliation', fn ($q) => $q->whereIn('status', Reconciliation::RESERVING_STATUSES)
            ->whereColumn('reconciliations.line_version', 'reconciliation_lines.version')->when($except, fn ($q) => $q->whereKeyNot($except)));
    }
}
