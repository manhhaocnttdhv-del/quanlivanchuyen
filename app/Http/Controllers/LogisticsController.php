<?php

namespace App\Http\Controllers;

use App\Http\Requests\LogisticsFilterRequest;
use App\Http\Requests\LogisticsRequest;
use App\Models\DeliveryDecision;
use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\HandoverDocument;
use App\Models\Order;
use App\Models\PickupAttempt;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseItem;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Policies\LogisticsPolicy;
use App\Services\AuditService;
use App\Services\DispatchReadiness;
use App\Services\DispatchService;
use App\Services\LogisticsEvidence;
use App\Services\OperationsWorklist;
use App\Services\OrderRouting;
use App\Services\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Điều phối, bàn giao và tồn; Service thay đổi nghiệp vụ, Controller chuẩn bị dữ liệu trong phạm vi kho. */
class LogisticsController extends Controller
{
    public function __construct(private LogisticsPolicy $policy) {}

    public function index(LogisticsFilterRequest $request, OperationsWorklist $worklist, DispatchReadiness $readiness)
    {
        $user = $request->user();
        $this->authorizeEntry($user);
        $filters = $request->validated();
        $tasks = DeliveryTask::with(['order.shop', 'shipper', 'warehouse'])->whereIn('warehouse_id', $this->warehouseIds($user))
            ->when(! $user->hasPermission('logistics.dispatch') && ! $user->hasPermission('logistics.warehouse'), fn ($q) => $q->where('shipper_id', $user->id))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%')));
        foreach (['warehouse_id', 'status', 'type'] as $field) {
            $tasks->when($filters[$field] ?? null, fn ($q, $v) => $q->where($field, $v));
        }
        $staff = $user->hasPermission('logistics.dispatch') || $user->hasPermission('logistics.warehouse');
        $queue = $filters['queue'] ?? 'all';
        $workView = $staff && ($filters['view'] ?? 'work') === 'work';
        $base = Order::whereIn('warehouse_id', $this->warehouseIds($user))
            ->whereNotIn('status', ['draft', 'needs_info', 'rejected', 'cancelled', 'delivered', 'returned', 'lost'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('tracking_code', 'like', $v.'%'));
        // Kho nhận có quyền với phiếu chuyển, chưa có quyền với đơn ở kho gửi.
        $incoming = WarehouseTransfer::whereIn('destination_warehouse_id', $this->warehouseIds($user))->whereIn('status', ['in_transit', 'partial'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('destination_warehouse_id', $id))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%')));
        $queueCounts = [];
        if ($staff) {
            foreach (OperationsWorklist::QUEUES as $key => $label) {
                $queueCounts[$key] = $key === 'receipt' ? (clone $incoming)->count() : $worklist->filter(clone $base, $key)->count();
            }
            $queueCounts['all'] += $queueCounts['receipt'];
        }
        $waiting = $staff ? $readiness->query($worklist->filter(clone $base, $queue))->oldest('updated_at')->orderBy('id')->paginate(12, ['*'], 'waiting_page')->withQueryString() : null;
        $workflow = $waiting?->getCollection()->mapWithKeys(fn ($order) => [$order->id => $readiness->inspect($order)]) ?? collect();

        return view('portal.logistics.index', ['tasks' => $tasks->latest('id')->paginate(20)->withQueryString(),
            'waiting' => $waiting, 'workflow' => $workflow, 'queue' => $queue, 'queueCounts' => $queueCounts, 'queues' => OperationsWorklist::QUEUES, 'workView' => $workView, 'reviewAccess' => $user->hasPermission('orders.review'),
            'incomingTransfers' => $staff && in_array($queue, ['all', 'receipt']) ? $incoming->with(['order', 'source', 'destination'])->withCount(['lines', 'lines as received_count' => fn ($q) => $q->whereNotNull('received_at')])->oldest('id')->paginate(8, ['*'], 'incoming_page')->withQueryString() : null,
            'warehouses' => Warehouse::whereIn('id', $this->warehouseIds($user))->pluck('name', 'id'), 'filters' => $filters]);
    }

    public function order(Request $request, Order $order, DispatchReadiness $readiness)
    {
        abort_unless($this->policy->dispatch($request->user(), $order->warehouse_id) || $this->policy->warehouse($request->user(), $order->warehouse_id), 403);
        $order->load(['shop', 'warehouse', 'parcels.holder', 'parcels.location', 'tasks.shipper', 'returnRecord', 'deliveryAttempts.proof', 'deliveryAttempts.shipper', 'deliveryDecisions', 'discrepancies.creator', 'discrepancies.resolver', 'discrepancies.item', 'discrepancies.order']);
        $shippers = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('name', 'shipper'))
            ->whereHas('warehouses', fn ($q) => $q->whereKey($order->warehouse_id))
            ->get();
        // Số nhiệm vụ là tải hiện tại; chỉ là gợi ý, điều phối vẫn quyết định theo tuyến.
        $loads = DeliveryTask::whereIn('status', DeliveryTask::ACTIVE)->whereIn('shipper_id', $shippers->pluck('id'))->selectRaw('shipper_id, COUNT(*) as total')->groupBy('shipper_id')->pluck('total', 'shipper_id');
        $options = $shippers->sortBy(fn ($u) => $loads[$u->id] ?? 0)->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.($loads[$u->id] ?? 0).' nhiệm vụ']);

        $routing = app(OrderRouting::class);
        $workflow = $readiness->inspect($order);
        $routeReady = $workflow['routeReady'];
        $decisions = ['return' => DeliveryDecision::TYPES['return']];
        if ($routeReady && ! $order->retry_authorized_attempt && $order->deliveryAttempts->first()?->outcome === 'failed') {
            if ($order->delivery_attempt_count < $order->delivery_attempt_limit) {
                $decisions = ['retry' => DeliveryDecision::TYPES['retry']] + $decisions;
            } elseif ($this->policy->approve($request->user(), $order->warehouse_id)) {
                $decisions = ['retry_exception' => DeliveryDecision::TYPES['retry_exception']] + $decisions;
            }
        }

        return view('portal.logistics.order', ['order' => $order, 'shippers' => $options, 'policy' => $this->policy,
            'routeReady' => $routeReady, 'decisions' => $decisions, 'workflow' => $workflow,
            'destinations' => $routing->destinations($order)->orderBy('name')->pluck('name', 'id'),
            'transfers' => $order->transfers()->with(['source', 'destination'])->latest('id')->get()]);
    }

    public function show(Request $request, DeliveryTask $task)
    {
        abort_unless($this->policy->view($request->user(), $task), 403);
        $task->load(['order.parcels.holder', 'order.parcels.location', 'order.shop', 'order.returnRecord', 'deliveryAttempt.proof', 'warehouse', 'shipper', 'pickupAttempt', 'assignments.actor', 'handovers.creator', 'handovers.confirmer']);

        return view('portal.logistics.task', ['task' => $task, 'order' => $task->order, 'policy' => $this->policy]);
    }

    public function assign(LogisticsRequest $request, Order $order, DispatchService $service)
    {
        $task = $service->assign($request->user(), $order, $request->validated());

        return to_route('logistics.show', $task)->with('success', 'Đã phân công. Người giữ kiện chỉ thay đổi khi nhận hàng.');
    }

    public function pickup(LogisticsRequest $request, DeliveryTask $task, DispatchService $service, LogisticsEvidence $evidence)
    {
        // Evidence wrapper dọn ảnh mới khi Service thất bại, tránh tệp mồ côi sau rollback.
        $evidence->run($request->file('evidence'), fn ($path) => $service->pickup($request->user(), $task, $request->validated(), $path));

        return back()->with('success', 'Đã ghi nhận kết quả lấy hàng.');
    }

    public function recall(LogisticsRequest $request, DeliveryTask $task, DispatchService $service)
    {
        $service->recall($request->user(), $task, $request->validated('reason'));

        return back()->with('success', 'Đã thu hồi. Nếu đang giữ kiện, shipper phải bàn giao về kho.');
    }

    public function authorizeReturn(LogisticsRequest $request, Order $order, DispatchService $service)
    {
        $service->authorizeReturn($request->user(), $order, $request->validated('reason'));

        return back()->with('success', 'Đã lưu quyết định hoàn và căn cứ.');
    }

    public function prepare(LogisticsRequest $request, DeliveryTask $task, WarehouseService $service)
    {
        $doc = $service->prepare($request->user(), $task, $request->validated('direction'), $request->validated('reason'));

        return to_route('logistics.handover', $doc)->with('success', 'Đã lập phiếu; đang chờ phía nhận kiểm đếm.');
    }

    public function handover(Request $request, HandoverDocument $handover)
    {
        abort_unless($this->policy->view($request->user(), $handover->task), 403);
        $handover->load(['order', 'task', 'lines.item', 'shipper', 'creator', 'confirmer']);

        return view('portal.logistics.handover', ['doc' => $handover, 'policy' => $this->policy,
            'locations' => WarehouseLocation::where('warehouse_id', $handover->warehouse_id)->where('is_active', true)->pluck('name', 'id')]);
    }

    public function confirm(LogisticsRequest $request, HandoverDocument $handover, WarehouseService $service, LogisticsEvidence $evidence)
    {
        $evidence->run($request->file('evidence'), fn ($path) => $service->confirm($request->user(), $handover, $request->validated(), $path));

        return back()->with('success', 'Đã xác nhận từng kiện và cập nhật người giữ.');
    }

    public function inventory(LogisticsFilterRequest $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('logistics.warehouse') || $user->hasPermission('logistics.dispatch'), 403);
        $ids = $this->warehouseIds($user);
        $filters = $request->validated();
        // Tồn chỉ gồm kiện kho đang thực giữ, không chỉ dựa vào trạng thái vận đơn.
        $items = WarehouseItem::with(['order', 'location'])->withCount(['discrepancies as hold_count' => fn ($q) => $q->where('status', 'open')->where('blocks_dispatch', true)])
            ->whereIn('warehouse_id', $ids)->where('holder_type', 'warehouse')
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%')))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('warehouse_location_id', $v))
            ->when($filters['hold'] ?? null, fn ($q) => $q->whereHas('discrepancies', fn ($q) => $q->where('status', 'open')->where('blocks_dispatch', true)));

        return view('portal.logistics.inventory', ['items' => $items->latest('id')->paginate(25)->withQueryString(), 'warehouses' => Warehouse::whereIn('id', $ids)->pluck('name', 'id'),
            'locations' => WarehouseLocation::with('warehouse')->whereIn('warehouse_id', $ids)->orderBy('code')->get(),
            'discrepancies' => Discrepancy::with(['order', 'item', 'creator', 'resolver'])->whereIn('warehouse_id', $ids)->orderByRaw("status = 'open' DESC")->latest('id')->paginate(15, ['*'], 'discrepancy_page')->withQueryString(), 'policy' => $this->policy, 'filters' => $filters]);
    }

    public function location(LogisticsRequest $request, AuditService $audit)
    {
        $data = $request->validated();
        abort_unless($this->policy->warehouse($request->user(), (int) $data['warehouse_id']), 403);
        // Khóa kho để kiểm tra mã vị trí trùng và ghi audit trong cùng giao dịch.
        DB::transaction(function () use ($data, $request, $audit) {
            Warehouse::lockForUpdate()->findOrFail($data['warehouse_id']);
            if (WarehouseLocation::where('warehouse_id', $data['warehouse_id'])->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Mã vị trí đã có trong kho này.']);
            }
            $location = WarehouseLocation::create($data);
            $audit->record($request->user(), $location, 'location.created', [], $data);
        });

        return back()->with('success', 'Đã thêm vị trí kho.');
    }

    public function resolve(LogisticsRequest $request, Discrepancy $discrepancy, WarehouseService $service)
    {
        $service->resolve($request->user(), $discrepancy, $request->validated('reason'));

        return back()->with('success', 'Đã xử lý sai lệch. Cước và tồn thực tế được giữ nguyên.');
    }

    public function weightReview(LogisticsRequest $request, Discrepancy $discrepancy, WarehouseService $service)
    {
        $service->requestWeightReview($request->user(), $discrepancy, $request->validated('reason'));

        return back()->with('success', 'Đã gửi đề nghị tính lại cước. Điều phối duyệt tại chi tiết vận đơn.');
    }

    public function evidence(Request $request, string $kind, int $id)
    {
        // kind là allowlist nghiệp vụ; quyền kiểm tra theo hồ sơ cha trước khi mở tệp private.
        if ($kind === 'pickup') {
            $attempt = PickupAttempt::findOrFail($id);
            $task = DeliveryTask::findOrFail($attempt->delivery_task_id);
            abort_unless($this->policy->view($request->user(), $task), 403);
            $path = $attempt->evidence_path;
        } elseif ($kind === 'handover') {
            $doc = HandoverDocument::findOrFail($id);
            abort_unless($this->policy->view($request->user(), $doc->task), 403);
            $path = $doc->evidence_path;
        } else {
            abort_unless($kind === 'discrepancy', 404);
            $difference = Discrepancy::findOrFail($id);
            abort_unless($this->policy->dispatch($request->user(), $difference->warehouse_id) || $this->policy->warehouse($request->user(), $difference->warehouse_id), 403);
            $path = $difference->evidence_path;
        }
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }

    private function warehouseIds(User $user)
    {
        return $user->hasRole('admin') ? Warehouse::pluck('id') : $user->warehouses()->pluck('warehouses.id');
    }

    private function authorizeEntry(User $user): void
    {
        abort_unless($user->hasPermission('logistics.dispatch') || $user->hasPermission('logistics.warehouse') || $user->hasRole('shipper'), 403);
    }
}
