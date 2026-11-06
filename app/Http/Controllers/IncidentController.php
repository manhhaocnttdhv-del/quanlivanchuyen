<?php

namespace App\Http\Controllers;

use App\Http\Requests\CaseFilterRequest;
use App\Http\Requests\IncidentRequest;
use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Policies\IncidentPolicy;
use App\Services\IncidentService;
use Illuminate\Http\Request;

/** Hồ sơ sự cố nội bộ; Service quản lý khóa giao/đối soát, bằng chứng và phê duyệt kết luận. */
class IncidentController extends Controller
{
    public function __construct(private IncidentPolicy $policy) {}

    public function index(CaseFilterRequest $request)
    {
        abort_unless($this->policy->viewAny($request->user()), 403);
        $filters = $request->validated();
        $query = Incident::visibleTo($request->user())->with(['order', 'assignee', 'warehouse', 'approvedAction'])
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('code', 'like', $v.'%')->orWhereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%'))))
            ->when($filters['mine'] ?? null, fn ($q) => $q->where('assigned_to', $request->user()->id));
        foreach (['status', 'type', 'severity', 'order_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $v) => $q->where($field, $v));
        }

        return view('portal.incidents.index', ['incidents' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(CaseFilterRequest $request)
    {
        abort_unless($this->policy->viewAny($request->user()), 403);
        $user = $request->user();
        $filters = $request->validated();
        $query = Order::whereNotNull('warehouse_id')->where('status', '!=', 'draft');
        if (! $user->hasRole('admin')) {
            $query->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'));
        }
        if (! $user->hasPermission('incidents.manage')) {
            // Shipper chỉ chọn được đơn đang có nhiệm vụ của mình, không duyệt mọi đơn trong kho.
            $query->whereHas('tasks', fn ($q) => $q->where('shipper_id', $user->id)->whereIn('status', DeliveryTask::ACTIVE));
        }
        $order = ! empty($filters['order_id']) ? (clone $query)->findOrFail($filters['order_id']) : null;
        $orders = $query->when($filters['q'] ?? null, fn ($q, $v) => $q->where('tracking_code', 'like', $v.'%'))->latest('id')->paginate(15)->withQueryString();
        $order?->load(['shop', 'parcels', 'tasks', 'collections', 'discrepancies']);

        return view('portal.incidents.create', compact('order', 'orders', 'filters'));
    }

    public function store(IncidentRequest $request, Order $order, IncidentService $service)
    {
        $incident = $service->create($request->user(), $order, $request->validated(), $request->file('evidence'));

        return to_route('incidents.show', $incident)->with('success', 'Đã mở hồ sơ sự cố và ghi nhận phạm vi khóa.');
    }

    public function show(Request $request, Incident $incident)
    {
        abort_unless($this->policy->view($request->user(), $incident), 403);
        $incident->load(['order', 'warehouse', 'creator', 'assignee', 'proposal.actor', 'approvedAction']);
        $handlers = User::where('status', 'active')->whereHas('roles.permissions', fn ($q) => $q->where('name', 'incidents.manage'))
            ->where(fn ($q) => $q->whereHas('warehouses', fn ($q) => $q->whereKey($incident->warehouse_id))->orWhereHas('roles', fn ($q) => $q->where('name', 'admin')))->orderBy('name')->pluck('name', 'id');

        return view('portal.incidents.show', ['incident' => $incident, 'handlers' => $handlers, 'policy' => $this->policy,
            'actions' => $incident->actions()->with(['actor', 'attachments'])->paginate(20)->withQueryString()]);
    }

    public function action(IncidentRequest $request, Incident $incident, string $operation, IncidentService $service)
    {
        // Mọi thao tác đi qua Service để kiểm tra revision, người duyệt khác người đề xuất và audit.
        $service->act($request->user(), $incident, $operation, $request->validated(), $request->file('evidence'));

        return back()->with('success', 'Đã lưu diễn biến xử lý sự cố.');
    }
}
