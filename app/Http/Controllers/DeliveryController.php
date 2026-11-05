<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryFilterRequest;
use App\Http\Requests\DeliveryRequest;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCollection;
use App\Models\DeliveryTask;
use App\Models\Order;
use App\Policies\LogisticsPolicy;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DeliveryController extends Controller
{
    public function mine(DeliveryFilterRequest $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('shipper'), 403);
        $filters = $request->validated();
        $base = DeliveryTask::where('shipper_id', $user->id)->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
        $active = ($filters['view'] ?? 'active') === 'active';
        $base = $active ? $base->whereIn('status', DeliveryTask::ACTIVE) : $base->whereNotIn('status', DeliveryTask::ACTIVE);
        $counts = (clone $base)->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');
        $tasks = $base->with(['order.returnRecord', 'warehouse', 'deliveryAttempt'])->where('type', $filters['type'] ?? 'delivery')
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%')))
            ->latest('id')->paginate(12)->withQueryString();

        return view('portal.delivery.mine', compact('tasks', 'filters', 'counts', 'active'));
    }

    public function record(DeliveryRequest $request, DeliveryTask $task, DeliveryService $service)
    {
        $service->record($request->user(), $task, $request->validated(), $request->file('evidence'));

        return to_route('logistics.show', $task)->with('success', 'Đã lưu kết quả và tiền thực thu.');
    }

    public function decide(DeliveryRequest $request, Order $order, DeliveryDecisionService $service)
    {
        $service->decide($request->user(), $order, $request->validated());

        return back()->with('success', 'Đã lưu quyết định và ý kiến shop.');
    }

    public function collections(DeliveryFilterRequest $request)
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin') || $user->hasRole('shipper') || $user->hasPermission('delivery.collections.view') || $user->shop, 403);
        $filters = $request->validated();
        $query = DeliveryCollection::visibleTo($user)
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%')))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['financial_status'] ?? null, fn ($q, $v) => $q->where('financial_status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('collected_at', '>=', Carbon::parse($v, 'Asia/Ho_Chi_Minh')->startOfDay()->utc()))
            ->when($filters['until'] ?? null, fn ($q, $v) => $q->where('collected_at', '<', Carbon::parse($v, 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->utc()));
        // Cộng trong MySQL DECIMAL; không chuyển số tiền sang float ở PHP.
        $totals = (clone $query)->selectRaw('type, SUM(amount) as amount, SUM(expected_amount) as expected')->groupBy('type')->get()->keyBy('type');
        $collections = $query->with(['order', 'shipper', 'attempt'])->latest('id')->paginate(20)->withQueryString();

        return view('portal.delivery.collections', compact('collections', 'totals', 'filters'));
    }

    public function proof(Request $request, DeliveryAttempt $attempt, LogisticsPolicy $policy)
    {
        $ownsShop = $attempt->order->shop->user_id === $request->user()->id;
        abort_unless($ownsShop || $policy->view($request->user(), $attempt->task), 403);
        $path = $attempt->proof?->getRawOriginal('evidence_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
