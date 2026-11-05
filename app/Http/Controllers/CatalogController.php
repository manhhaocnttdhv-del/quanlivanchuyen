<?php

namespace App\Http\Controllers;

use App\Http\Requests\AreaRequest;
use App\Http\Requests\WarehouseRequest;
use App\Models\Area;
use App\Models\Warehouse;
use App\Services\CatalogService;

/** Quản lý khu vực/kho qua middleware catalog.manage; Service ghi thay đổi và audit cùng lúc. */
class CatalogController extends Controller
{
    public function index()
    {
        return view('portal.catalog.index', ['areas' => Area::orderBy('name')->get(), 'warehouses' => Warehouse::with('area')->orderBy('name')->get()]);
    }

    public function area(?Area $area = null)
    {
        return view('portal.catalog.area', ['record' => $area]);
    }

    public function warehouse(?Warehouse $warehouse = null)
    {
        return view('portal.catalog.warehouse', ['record' => $warehouse, 'areas' => Area::where('is_active', true)->pluck('name', 'id')]);
    }

    public function saveArea(AreaRequest $request, CatalogService $service, ?Area $area = null)
    {
        $service->saveArea($request->user(), $request->validated(), $area);

        return redirect()->route('catalog.index')->with('status', 'Đã lưu khu vực.');
    }

    public function saveWarehouse(WarehouseRequest $request, CatalogService $service, ?Warehouse $warehouse = null)
    {
        $service->saveWarehouse($request->user(), $request->validated(), $warehouse);

        return redirect()->route('catalog.index')->with('status', 'Đã lưu kho.');
    }
}
