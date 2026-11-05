<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceFilterRequest;
use App\Http\Requests\FinanceRequest;
use App\Models\CashDeposit;
use App\Policies\FinancePolicy;
use App\Services\CashDepositService;
use App\Services\FinanceLedger;
use App\Services\FinanceQueries;
use Illuminate\Http\Request;

/** Điều phối đề nghị nộp, xác nhận thực nhận và phân bổ quỹ; Service khóa các nguồn tiền. */
class CashDepositController extends Controller
{
    public function create(FinanceFilterRequest $request, FinanceQueries $queries, FinancePolicy $policy)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('finance.manage') || $user->hasRole('shipper'), 403);
        $filters = $request->validated();
        $warehouses = $queries->warehouses($user);
        $warehouse = (int) ($filters['warehouse_id'] ?? $warehouses->keys()->first());
        $shippers = $queries->shippers($user, $warehouse);
        $shipper = (int) ($filters['shipper_id'] ?? $shippers->keys()->first());
        abort_unless($policy->deposit($user, $warehouse, $shipper), 403);
        // Chỉ lấy khoản thu hiệu lực shipper còn giữ; COD và phí vẫn là từng dòng riêng.
        $sources = $queries->collections($user, ['warehouse_id' => $warehouse, 'shipper_id' => $shipper] + $filters)
            ->havingRaw('amount + COALESCE(correction_amount, 0) - COALESCE(deposited_amount, 0) > 0')->oldest('id')->paginate(100)->withQueryString();

        return view('portal.finance.deposit-form', compact('sources', 'queries', 'warehouses', 'shippers', 'warehouse', 'shipper', 'filters'));
    }

    public function store(FinanceRequest $request, CashDepositService $service)
    {
        return to_route('finance.deposits.show', $service->create($request->user(), $request->validated()))->with('success', 'Đã gửi đề nghị nộp tiền. Chờ người khác xác nhận thực nhận.');
    }

    public function show(Request $request, CashDeposit $deposit, FinancePolicy $policy, FinanceLedger $ledger, FinanceQueries $queries)
    {
        abort_unless($policy->view($request->user(), $deposit), 403);
        $deposit->load(['creator', 'shipper', 'warehouse', 'confirmer', 'lines.collection.order', 'allocations.collection.order', 'allocations.creator', 'attachments']);

        // Chỉ kế toán đúng kho được chọn phân bổ phần dư sau khi quỹ thực nhận đã xác nhận.
        $remaining = $deposit->status === 'confirmed' && $ledger->unallocated($deposit) > 0 && $policy->staff($request->user(), $deposit->warehouse_id)
            ? $queries->collections($request->user(), ['warehouse_id' => $deposit->warehouse_id, 'shipper_id' => $deposit->shipper_id])->havingRaw('amount + COALESCE(correction_amount, 0) - COALESCE(deposited_amount, 0) > 0')->oldest('id')->limit(100)->get()
            : collect();

        return view('portal.finance.deposit', ['document' => $deposit, 'policy' => $policy, 'ledger' => $ledger, 'remaining' => $remaining, 'queries' => $queries,
            'events' => $deposit->events()->with('actor')->paginate(20)]);
    }

    public function action(FinanceRequest $request, CashDeposit $deposit, string $operation, CashDepositService $service)
    {
        // Route giới hạn tên thao tác; Request và Service tiếp tục kiểm tra người lập khác người duyệt.
        match ($operation) {
            'confirm' => $service->confirm($request->user(), $deposit, $request->validated(), $request->file('evidence')),
            'allocate' => $service->allocateRemaining($request->user(), $deposit, $request->validated()),
            'reject' => $service->reject($request->user(), $deposit, $request->validated()),
        };

        return back()->with('success', 'Đã ghi nhận xử lý phiếu nộp tiền.');
    }
}
