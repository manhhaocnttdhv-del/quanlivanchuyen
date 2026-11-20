<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseItem;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function __construct(private AuditService $audit) {}

    public function saveArea(User $actor, array $data, ?Area $area = null): void
    {
        DB::transaction(function () use ($actor, $data, $area) {
            $area = $area ? Area::lockForUpdate()->findOrFail($area->id) : new Area;
            $before = $area->only(['name', 'is_active']);
            $area->fill($data)->save();
            $this->audit->record($actor, $area, 'area.saved', $before, $data);
        });
    }

    public function saveWarehouse(User $actor, array $data, ?Warehouse $warehouse = null): void
    {
        DB::transaction(function () use ($actor, $data, $warehouse) {
            $warehouse = $warehouse ? Warehouse::lockForUpdate()->findOrFail($warehouse->id) : new Warehouse;
            if ($warehouse->exists && isset($data['area_id']) && $warehouse->area_id !== (int) $data['area_id']) {
                $used = Order::where('warehouse_id', $warehouse->id)->orWhere('origin_warehouse_id', $warehouse->id)->exists()
                    || WarehouseItem::where('warehouse_id', $warehouse->id)->exists()
                    || WarehouseTransfer::where('source_warehouse_id', $warehouse->id)->orWhere('destination_warehouse_id', $warehouse->id)->exists();
                if ($used) {
                    throw ValidationException::withMessages(['area_id' => 'Kho đã phát sinh vận đơn hoặc bàn giao không được đổi khu vực. Tạo kho mới để giữ đúng tuyến và lịch sử.']);
                }
            }
            $before = $warehouse->only(['name', 'area_id', 'is_active']);
            $warehouse->fill($data)->save();
            $this->audit->record($actor, $warehouse, 'warehouse.saved', $before, $data);
        });
    }
}
