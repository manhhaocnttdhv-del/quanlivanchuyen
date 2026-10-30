<?php

namespace App\Http\Controllers;

use App\Http\Requests\CaseFilterRequest;
use App\Http\Requests\ComplaintRequest;
use App\Models\Complaint;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Policies\ComplaintPolicy;
use App\Services\ComplaintService;
use Illuminate\Http\Request;

/** Khiếu nại của shop và trao đổi CSKH; ComplaintService quản lý SLA, revision và lịch sử. */
class ComplaintController extends Controller
{
    public function __construct(private ComplaintPolicy $policy) {}

    public function index(CaseFilterRequest $request)
    {
        abort_unless($this->policy->viewAny($request->user()), 403);
        $filters = $request->validated();
        $base = Complaint::visibleTo($request->user());
        // Số quá hạn được tính trong phạm vi người dùng, trước các bộ lọc của danh sách.
        $overdueCount = (clone $base)->overdue()->count();
        $query = $base->with(['order', 'assignee'])->when($filters['overdue'] ?? null, fn ($q) => $q->overdue())
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('code', 'like', $v.'%')->orWhereHas('order', fn ($q) => $q->where('tracking_code', 'like', $v.'%'))))
            ->when($filters['mine'] ?? null, fn ($q) => $q->where('assigned_to', $request->user()->id));
        foreach (['status', 'type', 'order_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $v) => $q->where($field, $v));
        }

        return view('portal.complaints.index', ['complaints' => $query->latest('id')->paginate(20)->withQueryString(), 'filters' => $filters, 'overdueCount' => $overdueCount]);
    }

    public function create(CaseFilterRequest $request)
    {
        abort_unless($request->user()->shop, 403);
        $filters = $request->validated();
        $query = Order::where('shop_id', $request->user()->shop->id)->whereNotNull('submitted_at')->whereNotNull('warehouse_id');
        $order = ! empty($filters['order_id']) ? (clone $query)->findOrFail($filters['order_id']) : null;
        $orders = $query->when($filters['q'] ?? null, fn ($q, $v) => $q->where('tracking_code', 'like', $v.'%'))->latest('id')->paginate(15)->withQueryString();

        return view('portal.complaints.create', compact('order', 'orders', 'filters'));
    }

    public function store(ComplaintRequest $request, Order $order, ComplaintService $service)
    {
        $complaint = $service->create($request->user(), $order, $request->validated(), $request->file('evidence'));

        return to_route('complaints.show', $complaint)->with('success', 'Đã gửi khiếu nại. Hạn xử lý được ghi trong hồ sơ.');
    }

    public function show(Request $request, Complaint $complaint)
    {
        abort_unless($this->policy->view($request->user(), $complaint), 403);
        $staff = $this->policy->manage($request->user(), $complaint);
        $complaint->load(['order', 'shop', 'assignee']);
        // Lọc tin nội bộ ngay trong SQL trước phân trang; Blade không phải lớp bảo mật dữ liệu.
        $messages = $complaint->messages()->when(! $staff, fn ($q) => $q->where('is_internal', false))->with(['actor', 'attachments'])->paginate(20)->withQueryString();
        $handlers = $staff ? User::where('status', 'active')->whereHas('roles.permissions', fn ($q) => $q->where('name', 'complaints.manage'))
            ->where(fn ($q) => $q->whereHas('warehouses', fn ($q) => $q->whereKey($complaint->warehouse_id))->orWhereHas('roles', fn ($q) => $q->where('name', 'admin')))->orderBy('name')->pluck('name', 'id') : collect();
        $incidents = $staff ? Incident::visibleTo($request->user())->where('order_id', $complaint->order_id)->latest('id')->get(['id', 'code', 'status']) : collect();

        return view('portal.complaints.show', compact('complaint', 'staff', 'messages', 'handlers', 'incidents'));
    }

    public function action(ComplaintRequest $request, Complaint $complaint, string $operation, ComplaintService $service)
    {
        $service->act($request->user(), $complaint, $operation, $request->validated(), $request->file('evidence'));

        return back()->with('success', 'Đã cập nhật khiếu nại.');
    }
}
