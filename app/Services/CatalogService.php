<?php

namespace App\Services;

use App\Models\Area;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function __construct(private AuditService $audit) {}

    public function saveArea(User $actor, array $data, ?Area $area = null): void
    {
        DB::transaction(function () use ($actor, $data, $area) {
            $area ??= new Area;
            $before = $area->only(['name', 'is_active']);
            $area->fill($data)->save();
            $this->audit->record($actor, $area, 'area.saved', $before, $data);
        });
    }

    public function saveWarehouse(User $actor, array $data, ?Warehouse $warehouse = null): void
    {
        DB::transaction(function () use ($actor, $data, $warehouse) {
            $warehouse ??= new Warehouse;
            $before = $warehouse->only(['name', 'area_id', 'is_active']);
            $warehouse->fill($data)->save();
            $this->audit->record($actor, $warehouse, 'warehouse.saved', $before, $data);
        });
    }
}
