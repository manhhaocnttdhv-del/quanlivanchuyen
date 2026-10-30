<?php

namespace App\Http\Controllers;

use App\Http\Requests\LogisticsRequest;
use App\Models\Stocktake;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Policies\LogisticsPolicy;
use App\Services\StocktakeService;
use Illuminate\Http\Request;

/** Kiểm kê từ snapshot tồn; StocktakeService ghi chênh lệch và yêu cầu người khác duyệt. */
class StocktakeController extends Controller
{
    public function __construct(private LogisticsPolicy $policy) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('logistics.warehouse') || $user->hasPermission('logistics.approve'), 403);
        $ids = $user->hasRole('admin') ? Warehouse::pluck('id') : $user->warehouses()->pluck('warehouses.id');

        return view('portal.logistics.stocktakes', ['stocktakes' => Stocktake::with(['warehouse', 'location', 'creator', 'approver'])->withCount('lines')->whereIn('warehouse_id', $ids)->latest('id')->paginate(20),
            'warehouses' => Warehouse::whereIn('id', $ids)->pluck('name', 'id'), 'locations' => WarehouseLocation::with('warehouse')->whereIn('warehouse_id', $ids)->get()]);
    }

    public function store(LogisticsRequest $request, StocktakeService $service)
    {
        $stocktake = $service->start($request->user(), $request->validated());

        return to_route('stocktakes.show', $stocktake)->with('success', 'Đã chụp snapshot tồn kho để kiểm đếm.');
    }

    public function show(Request $request, Stocktake $stocktake)
    {
        // Đếm theo lô 100; số chưa đếm lấy trên toàn snapshot, không chỉ trang đang mở.
        abort_unless($this->policy->warehouse($request->user(), $stocktake->warehouse_id) || $this->policy->approve($request->user(), $stocktake->warehouse_id), 403);
        $stocktake->load(['warehouse', 'location', 'creator', 'approver', 'discrepancies.item', 'discrepancies.creator', 'discrepancies.resolver', 'discrepancies.order']);

        return view('portal.logistics.stocktake', ['stocktake' => $stocktake, 'policy' => $this->policy,
            'lines' => $stocktake->lines()->with('item.order')->orderBy('id')->paginate(100), 'uncounted' => $stocktake->lines()->whereNull('actual_present')->count()]);
    }

    public function submit(LogisticsRequest $request, Stocktake $stocktake, StocktakeService $service)
    {
        // finalize=0 lưu trang hiện tại; finalize=1 chỉ gửi khi toàn bộ snapshot đã kiểm đếm.
        $service->submit($request->user(), $stocktake, $request->validated());

        return back()->with('success', $request->boolean('finalize', true) ? 'Đã gửi kiểm đếm, chờ người khác xác nhận.' : 'Đã lưu kết quả đếm của trang này.');
    }

    public function approve(LogisticsRequest $request, Stocktake $stocktake, StocktakeService $service)
    {
        $service->approve($request->user(), $stocktake, $request->validated('reason'));

        return back()->with('success', 'Đã xác nhận biên bản; chênh lệch được lập hồ sơ riêng.');
    }
}
