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

    public function orders(User $user, string $type)
    {
        abort_unless($this->policy->view($user, $type), 403);
        $query = Order::whereNotNull('submitted_at');
        if (! $user->hasRole('admin')) {
            $this->policy->staff($user, $type)
                ? $query->whereIn('orders.warehouse_id', $user->warehouses()->select('warehouses.id'))
                : $query->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query;
    }

    public function filterOrders($query, array $filters, string $shipperColumn = 'orders.assigned_shipper_id'): void
    {
        foreach (['warehouse_id', 'shop_id', 'area_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $id) => $q->where('orders.'.$field, $id));
        }
        $query->when($filters['shipper_id'] ?? null, fn ($q, $id) => $q->where($shipperColumn, $id));
    }

    public function choices(User $user, string $type): array
    {
        $orders = $this->orders($user, $type);
        $warehouses = Warehouse::query();
        if (! $user->hasRole('admin')) {
            $warehouses->whereIn('id', $this->policy->staff($user, $type)
                ? $user->warehouses()->select('warehouses.id') : (clone $orders)->select('warehouse_id'));
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
