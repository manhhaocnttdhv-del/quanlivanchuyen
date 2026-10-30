<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinanceFilterRequest;
use App\Models\Adjustment;
use App\Models\CashDeposit;
use App\Models\DeliveryCollection;
use App\Models\FeeReceipt;
use App\Models\FinanceAttachment;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Policies\FinancePolicy;
use App\Services\FinanceQueries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinanceController extends Controller
{
    public function index(FinanceFilterRequest $request, FinanceQueries $queries, string $section = 'collections')
    {
        $user = $request->user();
        $filters = $request->validated();
        $staff = $user->hasPermission('finance.manage');
        abort_if($section === 'adjustments' && ! $staff, 403);
        $totals = collect();
        if ($section === 'collections') {
            $query = $queries->collections($user, $filters);
            // Tong toan bo ket qua loc; khong cong chi trang hien tai, khong tai tat ca dong vao RAM.
            $totals = DB::query()->fromSub((clone $query)->toBase(), 'sources')->selectRaw('type, SUM(amount) AS original, SUM(COALESCE(correction_amount, 0)) AS corrections, SUM(COALESCE(deposited_amount, 0)) AS deposited, SUM(amount + COALESCE(correction_amount, 0) - COALESCE(deposited_amount, 0)) AS held')->groupBy('type')->get();
            $statuses = [];
        } elseif ($section === 'fees') {
            $query = $queries->fees($user, $filters);
            $statuses = [];
        } else {
            $model = match ($section) {
                'deposits' => CashDeposit::class, 'reconciliations' => Reconciliation::class,
                'receipts' => FeeReceipt::class, 'adjustments' => Adjustment::class,
            };
            $query = $model::visibleTo($user)->with('creator', 'warehouse');
            $query->when($filters['q'] ?? null, fn ($q, $v) => $q->where('code', 'like', $v.'%'));
            $query = $queries->filter($query, array_diff_key($filters, ['q' => true]));
            $query->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
            if ($section === 'deposits') {
                $query->with('shipper');
            } elseif ($section === 'adjustments') {
                $query->with('order');
            } else {
                $query->with('shop');
            }
            $statuses = $model::STATUSES;
        }

        return view('portal.finance.index', ['records' => $query->latest('id')->paginate(20)->withQueryString(),
            'section' => $section, 'filters' => $filters, 'totals' => $totals, 'queries' => $queries, 'staff' => $staff,
            'statuses' => $statuses, 'warehouses' => $staff ? $queries->warehouses($user) : collect()]);
    }

    public function collection(FinanceFilterRequest $request, DeliveryCollection $collection, FinanceQueries $queries)
    {
        abort_unless(DeliveryCollection::visibleTo($request->user())->whereKey($collection->id)->exists(), 403);
        $source = $queries->collections($request->user(), [])->findOrFail($collection->id);
        $source->load(['depositAllocations.deposit', 'adjustmentLines.adjustment', 'reconciliationLines.reconciliation']);

        return view('portal.finance.collection', ['source' => $source, 'queries' => $queries, 'policy' => app(FinancePolicy::class)]);
    }

    public function fee(FinanceFilterRequest $request, Receivable $fee, FinanceQueries $queries)
    {
        $source = $queries->fees($request->user(), [])->findOrFail($fee->id);
        $source->load(['receiptAllocations.receipt', 'adjustmentLines.adjustment', 'reconciliationLines.reconciliation']);

        return view('portal.finance.fee', ['source' => $source, 'queries' => $queries, 'policy' => app(FinancePolicy::class)]);
    }

    public function attachment(Request $request, FinanceAttachment $attachment, FinancePolicy $policy)
    {
        abort_unless($policy->view($request->user(), $attachment->document()), 403);
        $path = $attachment->getRawOriginal('path');
        abort_unless(Storage::disk('local')->exists($path), 404);

        $extension = match ($attachment->mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'bin',
        };

        return response()->download(Storage::disk('local')->path($path), 'chung-tu-'.$attachment->id.'.'.$extension,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $attachment->mime])->setPrivate();
    }
}
