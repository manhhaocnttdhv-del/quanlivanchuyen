<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceFilterRequest;
use App\Http\Requests\FinanceRequest;
use App\Models\Adjustment;
use App\Models\Reconciliation;
use App\Policies\FinancePolicy;
use App\Services\FinanceLedger;
use App\Services\FinanceQueries;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function create(FinanceFilterRequest $request, FinanceQueries $queries, FinancePolicy $policy)
    {
        abort_unless($request->user()->hasPermission('finance.manage'), 403);
        $filters = $request->validated();
        $warehouses = $queries->warehouses($request->user());
        $shops = $queries->shops($request->user());
        $filters += ['warehouse_id' => $warehouses->keys()->first(), 'shop_id' => $shops->keys()->first(), 'from' => now()->startOfMonth()->format('Y-m-d'), 'until' => now()->format('Y-m-d')];
        abort_unless($policy->staff($request->user(), (int) $filters['warehouse_id']), 403);

        return view('portal.finance.reconciliation-form', ['filters' => $filters, 'warehouses' => $warehouses, 'shops' => $shops,
            'queries' => $queries, 'document' => null] + $this->sources($request, $queries, $filters));
    }

    public function store(FinanceRequest $request, ReconciliationService $service)
    {
        return to_route('finance.reconciliations.show', $service->create($request->user(), $request->validated()))->with('success', 'Đã tạo bản nháp đối soát và giữ chỗ nguồn tiền.');
    }

    public function show(Request $request, Reconciliation $reconciliation, FinancePolicy $policy, FinanceLedger $ledger)
    {
        abort_unless($policy->view($request->user(), $reconciliation), 403);
        $reconciliation->load(['creator', 'shop', 'warehouse', 'approver', 'lines.order']);

        return view('portal.finance.reconciliation', ['document' => $reconciliation, 'policy' => $policy, 'ledger' => $ledger,
            'events' => $reconciliation->events()->with('actor')->paginate(20),
            'payments' => $reconciliation->payments()->with('attachments')->latest('id')->get()]);
    }

    public function edit(FinanceFilterRequest $request, Reconciliation $reconciliation, FinancePolicy $policy, FinanceQueries $queries)
    {
        abort_unless($policy->staff($request->user(), $reconciliation->warehouse_id), 403);
        abort_unless(in_array($reconciliation->status, ['draft', 'returned']) && ! $reconciliation->approved_at, 422);
        $filters = ['warehouse_id' => $reconciliation->warehouse_id, 'shop_id' => $reconciliation->shop_id, 'from' => $reconciliation->period_start->format('Y-m-d'), 'until' => $reconciliation->period_end->format('Y-m-d')];
        $reconciliation->load('lines');

        return view('portal.finance.reconciliation-form', ['document' => $reconciliation, 'filters' => $filters, 'queries' => $queries,
            'warehouses' => $queries->warehouses($request->user()), 'shops' => $queries->shops($request->user())] + $this->sources($request, $queries, $filters, $reconciliation->id));
    }

    public function action(FinanceRequest $request, Reconciliation $reconciliation, string $operation, ReconciliationService $service)
    {
        $service->act($request->user(), $reconciliation, $operation, $request->validated());

        return to_route('finance.reconciliations.show', $reconciliation)->with('success', 'Đã lưu trạng thái và lịch sử đối soát.');
    }

    public function payment(FinanceRequest $request, Reconciliation $reconciliation, PaymentService $service)
    {
        $service->record($request->user(), $reconciliation, $request->validated(), $request->file('evidence'));

        return back()->with('success', 'Đã lưu giao dịch trả shop và kết quả ngân hàng.');
    }

    private function sources(Request $request, FinanceQueries $queries, array $filters, ?int $except = null): array
    {
        $eligible = fn ($q) => $q->where('financial_hold', false)->whereDoesntHave('incidents', fn ($q) => $q->where('blocks_reconciliation', true));
        $cod = $queries->collections($request->user(), $filters + ['type' => 'cod'], $except)->whereHas('order', $eligible)
            ->havingRaw('LEAST(amount + COALESCE(correction_amount, 0), COALESCE(deposited_amount, 0)) - COALESCE(reserved_amount, 0) > 0')->oldest('id')->limit(100)->get();
        $fees = $queries->fees($request->user(), $filters, $except)->whereNotNull('order_id')->whereHas('order', $eligible)
            ->havingRaw('amount + COALESCE(correction_amount, 0) - COALESCE(received_amount, 0) - COALESCE(reserved_amount, 0) > 0')->oldest('id')->limit(100)->get();
        $adjustments = Adjustment::visibleTo($request->user())->where('warehouse_id', $filters['warehouse_id'])->where('kind', 'compensation')->where('status', 'approved')->whereDoesntHave('reversal')
            ->whereHas('order', fn ($q) => $eligible($q)->where('shop_id', $filters['shop_id']))
            ->whereDoesntHave('reservedLines', fn ($q) => $q->whereHas('reconciliation', fn ($q) => $q->whereIn('status', Reconciliation::RESERVING_STATUSES)->whereColumn('reconciliations.line_version', 'reconciliation_lines.version')->when($except, fn ($q) => $q->whereKeyNot($except))))->with('order');
        $adjustments = $queries->filter($adjustments, array_diff_key($filters, ['q' => true]), 'approved_at')->oldest('id')->limit(100)->get();

        return compact('cod', 'fees', 'adjustments');
    }
}
