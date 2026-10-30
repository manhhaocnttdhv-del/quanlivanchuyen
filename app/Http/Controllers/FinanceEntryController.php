<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceFilterRequest;
use App\Http\Requests\FinanceRequest;
use App\Models\Adjustment;
use App\Models\FeeReceipt;
use App\Models\Incident;
use App\Models\Order;
use App\Policies\FinancePolicy;
use App\Services\AdjustmentService;
use App\Services\FeeReceiptService;
use App\Services\FinanceQueries;
use Illuminate\Http\Request;

/** Phiếu thu phí và điều chỉnh có căn cứ; các Service giữ quy tắc tiền, chứng từ và người duyệt. */
class FinanceEntryController extends Controller
{
    public function receiptForm(FinanceFilterRequest $request, FinanceQueries $queries, FinancePolicy $policy)
    {
        abort_unless($request->user()->hasPermission('finance.manage'), 403);
        $filters = $request->validated();
        $warehouses = $queries->warehouses($request->user());
        $shops = $queries->shops($request->user());
        $filters += ['warehouse_id' => $warehouses->keys()->first(), 'shop_id' => $shops->keys()->first()];
        abort_unless($policy->staff($request->user(), (int) $filters['warehouse_id']), 403);
        // Trừ cả phần đã thu và đang giữ đối soát để không chọn cùng nghĩa vụ hai lần.
        $fees = $queries->fees($request->user(), $filters)
            ->havingRaw('amount + COALESCE(correction_amount, 0) - COALESCE(received_amount, 0) - COALESCE(reserved_amount, 0) > 0')->oldest('id')->paginate(100)->withQueryString();

        return view('portal.finance.receipt-form', compact('queries', 'filters', 'warehouses', 'shops', 'fees'));
    }

    public function receiptStore(FinanceRequest $request, FeeReceiptService $service)
    {
        return to_route('finance.receipts.show', $service->record($request->user(), $request->validated(), $request->file('evidence')))->with('success', 'Đã ghi nhận tiền thu nợ phí.');
    }

    public function receipt(Request $request, FeeReceipt $receipt, FinancePolicy $policy)
    {
        abort_unless($policy->view($request->user(), $receipt), 403);
        $receipt->load(['shop', 'warehouse', 'creator', 'allocations.receivable.order', 'allocations.receivable.reconciliation', 'attachments']);

        return view('portal.finance.receipt', ['document' => $receipt, 'events' => $receipt->events()->with('actor')->paginate(20)]);
    }

    public function adjustmentForm(FinanceFilterRequest $request, FinancePolicy $policy)
    {
        abort_unless($request->user()->hasPermission('finance.manage'), 403);
        $filters = $request->validated();
        $orders = Order::whereNotNull('warehouse_id')->when(! $request->user()->hasRole('admin'), fn ($q) => $q->whereIn('warehouse_id', $request->user()->warehouses()->select('warehouses.id')))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('tracking_code', 'like', $v.'%'));
        $order = ! empty($filters['order_id']) ? (clone $orders)->findOrFail($filters['order_id']) : null;
        if ($order) {
            abort_unless($policy->staff($request->user(), $order->warehouse_id), 403);
            $order->load('collections');
        }
        $incidents = $order ? Incident::where('order_id', $order->id)->where('financial_status', 'pending_finance')->where('status', 'closed')->with('approvedAction')->get() : collect();
        // Đề xuất dựa trên cùng vận đơn; Service kiểm tra lại căn cứ và khóa khi ghi thật.
        $originals = $order ? Adjustment::where('order_id', $order->id)->where('status', 'approved')->where('kind', '!=', 'reversal')->whereDoesntHave('reversal')->get() : collect();
        $fees = $order ? $order->getConnection()->table('receivables')->where('order_id', $order->id)->get() : collect();

        return view('portal.finance.adjustment-form', ['orders' => $orders->latest('id')->paginate(15)->withQueryString(), 'order' => $order,
            'incidents' => $incidents, 'originals' => $originals, 'fees' => $fees, 'filters' => $filters]);
    }

    public function adjustmentStore(FinanceRequest $request, Order $order, AdjustmentService $service)
    {
        return to_route('finance.adjustments.show', $service->create($request->user(), $order, $request->validated(), $request->file('evidence')))->with('success', 'Đã gửi điều chỉnh để người khác duyệt.');
    }

    public function adjustment(Request $request, Adjustment $adjustment, FinancePolicy $policy)
    {
        abort_unless($policy->view($request->user(), $adjustment), 403);
        $adjustment->load(['order', 'incident', 'original', 'line.collection', 'line.receivable', 'approver', 'warehouse', 'creator', 'attachments', 'reversal']);

        return view('portal.finance.adjustment', ['document' => $adjustment, 'policy' => $policy, 'events' => $adjustment->events()->with('actor')->paginate(20)]);
    }

    public function adjustmentReview(FinanceRequest $request, Adjustment $adjustment, string $operation, AdjustmentService $service)
    {
        $service->review($request->user(), $adjustment, $operation, $request->validated());

        return back()->with('success', 'Đã ghi nhận kết quả xét duyệt điều chỉnh.');
    }
}
