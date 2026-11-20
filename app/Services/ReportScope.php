<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\ReportPolicy;
use Illuminate\Support\Facades\DB;

class ReportScope
{
    public function __construct(private ReportPolicy $policy) {}

    public function orders(User $user, string $type, string $warehouseColumn = 'orders.warehouse_id')
    {
        abort_unless($this->policy->view($user, $type), 403);
        $query = Order::whereNotNull('submitted_at');
        if (! $user->hasRole('admin')) {
            $this->policy->staff($user, $type)
                ? $query->whereIn($warehouseColumn, $user->warehouses()->select('warehouses.id'))
                : $query->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query;
    }

    public function inventoryOrders(User $user)
    {
        // Mot don chi dem mot lan tai moi kho dang thuc giu kien, ke ca moi nhan mot phan.
        $stock = DB::table('warehouse_items')->where('holder_type', 'warehouse')->select('order_id', 'warehouse_id')->distinct();

        return $this->orders($user, 'operations', 'inventory_stock.warehouse_id')
            ->joinSub($stock, 'inventory_stock', fn ($join) => $join->on('inventory_stock.order_id', '=', 'orders.id'));
    }

    public function filterOrders($query, array $filters, string $shipperColumn = 'orders.assigned_shipper_id', string $warehouseColumn = 'orders.warehouse_id'): void
    {
        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where($warehouseColumn, $id));
        foreach (['shop_id', 'area_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $id) => $q->where('orders.'.$field, $id));
        }
        $query->when($filters['shipper_id'] ?? null, fn ($q, $id) => $q->where($shipperColumn, $id));
    }

    public function choices(User $user, string $type): array
    {
        $orders = $this->orders($user, $type);
        if ($type === 'operations' && ! $user->hasRole('admin') && $this->policy->staff($user, $type)) {
            $visibleIds = (clone $orders)->select('orders.id')->union($this->inventoryOrders($user)->select('orders.id'));
            $orders = Order::whereIn('orders.id', $visibleIds);
        }
        $warehouses = Warehouse::query();
        if (! $user->hasRole('admin')) {
            $warehouseIds = $this->policy->staff($user, $type)
                ? $user->warehouses()->select('warehouses.id') : (clone $orders)->select('warehouse_id');
            if ($type === 'operations' && ! $this->policy->staff($user, $type)) {
                $warehouseIds->union($this->inventoryOrders($user)->select('inventory_stock.warehouse_id'));
            }
            $warehouses->whereIn('id', $warehouseIds);
        }

        return [
            'warehouse_id' => $warehouses->orderBy('name')->pluck('name', 'id'),
            'shop_id' => Shop::whereIn('id', (clone $orders)->select('shop_id'))->orderBy('name')->pluck('name', 'id'),
            'area_id' => Area::whereIn('id', (clone $orders)->select('area_id'))->orderBy('name')->pluck('name', 'id'),
            'shipper_id' => User::where(function ($q) use ($orders) {
                $q->whereIn('id', (clone $orders)->select('assigned_shipper_id'))
                    ->orWhereIn('id', DB::table('delivery_tasks')->whereIn('order_id', (clone $orders)->select('orders.id'))->select('shipper_id'));
            })->orderBy('name')->pluck('name', 'id'),
        ];
    }

    public function validate(User $user, string $type, array $filters): void
    {
        foreach ($this->choices($user, $type) as $field => $choices) {
            abort_if(! empty($filters[$field]) && ! $choices->has($filters[$field]), 403);
        }
    }

    public function hash(User $user, string $type): string
    {
        abort_unless($this->policy->view($user, $type), 403);
        // Kiem tra lai khi xu ly va tai tep; quyen/kho thay doi thi tep cu khong con hop le.
        $scope = $user->hasRole('admin') ? ['admin'] : ($this->policy->staff($user, $type)
            ? ['warehouses', $user->warehouses()->orderBy('warehouses.id')->pluck('warehouses.id')->all()]
            : ['shop', $user->shop?->id]);

        return hash('sha256', json_encode([$type, $scope], JSON_THROW_ON_ERROR));
    }
}
