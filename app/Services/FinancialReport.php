<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\Payment;
use App\Models\Reconciliation;
use App\Models\User;
use App\Policies\ReportPolicy;
use App\Support\ReportPeriod;
use App\Support\ReportValue;
use Illuminate\Support\Facades\DB;

class FinancialReport
{
    public const LEDGERS = ['collections' => 'COD và tiền thu', 'fees' => 'Phí và công nợ',
        'reconciliations' => 'Phải trả shop', 'payments' => 'Giao dịch chi shop', 'deposits' => 'Tiền nộp quỹ'];

    public function __construct(private FinanceQueries $finance, private ReportScope $scope, private ReportPolicy $policy) {}

    public function query(User $user, array $filters)
    {
        return match ($filters['ledger']) {
            'collections' => $this->collections($user, $filters),
            'fees' => $this->fees($user, $filters),
            'reconciliations' => $this->reconciliations($user, $filters),
            'payments' => $this->payments($user, $filters),
            'deposits' => $this->deposits($user, $filters),
        };
    }

    private function collections(User $user, array $filters)
    {
        $source = $this->finance->collections($user, $filters);
        // Tai su dung ledger, nhung phai thu hep lai dung quyen bao cao neu user co nhieu vai tro.
        $source->whereIn('order_id', $this->scope->orders($user, 'finance')->select('orders.id'));
        $source->whereHas('order', fn ($q) => $q->when($filters['area_id'] ?? null, fn ($q, $id) => $q->where('area_id', $id)));
        $effective = 's.amount + COALESCE(s.correction_amount, 0)';
        $deposited = 'COALESCE(s.deposited_amount, 0)';
        $reserved = 'COALESCE(s.reserved_amount, 0)';

        return DB::query()->fromSub($source->toBase(), 's')->join('orders as o', 'o.id', '=', 's.order_id')
            ->join('shops', 'shops.id', '=', 'o.shop_id')->join('warehouses', 'warehouses.id', '=', 's.warehouse_id')
            ->leftJoin('areas', 'areas.id', '=', 'o.area_id')->leftJoin('users', 'users.id', '=', 's.shipper_id')
            ->selectRaw('s.id, o.tracking_code AS code, s.collected_at AS occurred_at, s.type AS category,
                o.shop_id, s.warehouse_id, o.area_id, s.shipper_id, shops.name AS shop, warehouses.name AS warehouse,
                areas.name AS area, users.name AS shipper, s.amount AS original, COALESCE(s.correction_amount, 0) AS corrections,
                '.$effective.' AS effective, '.$deposited.' AS deposited, '.$effective.' - '.$deposited.' AS held,
                '.$reserved.' AS reserved, LEAST('.$effective.', '.$deposited.') - '.$reserved.' AS available,
                '.$effective.' - '.$reserved.' AS unreconciled');
    }

    private function fees(User $user, array $filters)
    {
        $source = $this->finance->fees($user, $filters)
            ->withSum(['reconciliationLines as settled_amount' => fn ($q) => $q->whereHas('reconciliation', fn ($q) => $q
                ->whereNotNull('approved_at')->whereIn('status', Reconciliation::RESERVING_STATUSES)
                ->whereColumn('reconciliations.line_version', 'reconciliation_lines.version'))], 'amount');
        if (! $this->policy->staff($user, 'finance')) {
            $source->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
        }
        $effective = 's.amount + COALESCE(s.correction_amount, 0)';
        $received = 'COALESCE(s.received_amount, 0)';
        $reserved = 'COALESCE(s.reserved_amount, 0)';
        $settled = 'COALESCE(s.settled_amount, 0)';

        return DB::query()->fromSub($source->toBase(), 's')->join('shops', 'shops.id', '=', 's.shop_id')
            ->join('warehouses', 'warehouses.id', '=', 's.warehouse_id')
            ->leftJoin('orders as o', 'o.id', '=', 's.order_id')->leftJoin('reconciliations as r', 'r.id', '=', 's.reconciliation_id')
            ->selectRaw('s.id, COALESCE(o.tracking_code, r.code) AS code, s.accrued_at AS occurred_at, s.type AS category,
                s.shop_id, s.warehouse_id, shops.name AS shop, warehouses.name AS warehouse, s.amount AS original,
                COALESCE(s.correction_amount, 0) AS corrections, '.$effective.' AS effective, '.$received.' AS received,
                '.$reserved.' AS reserved, '.$settled.' AS settled, '.$effective.' - '.$received.' - '.$settled.' AS outstanding,
                '.$effective.' - '.$received.' - '.$reserved.' AS available');
    }

    private function reconciliations(User $user, array $filters)
    {
        $source = Reconciliation::whereNotNull('approved_at')->withSum('paymentAllocations as paid_amount', 'amount');
        if (! $user->hasRole('admin')) {
            $this->policy->staff($user, 'finance')
                ? $source->whereIn('warehouse_id', $user->warehouses()->select('warehouses.id'))
                : $source->whereHas('shop', fn ($q) => $q->where('user_id', $user->id));
        }
        // Ban tung duyet van la nghia vu phai tra khi dang duyet lai ngan hang.
        $this->documentFilters($source, $filters, 'created_at');

        return DB::query()->fromSub($source->toBase(), 's')->join('shops', 'shops.id', '=', 's.shop_id')
            ->join('warehouses', 'warehouses.id', '=', 's.warehouse_id')
            ->selectRaw('s.id, s.code, s.created_at AS occurred_at, s.status AS category, s.shop_id, s.warehouse_id,
                shops.name AS shop, warehouses.name AS warehouse, s.cod_total AS cod, s.fee_total AS fees,
                s.adjustment_total AS compensation, s.payable_amount AS payable, COALESCE(s.paid_amount, 0) AS paid,
                s.payable_amount - COALESCE(s.paid_amount, 0) AS remaining, s.debt_amount AS debt');
    }

    private function payments(User $user, array $filters)
    {
        $source = Payment::visibleTo($user)->withSum('allocation as paid_amount', 'amount');
        if (! $this->policy->staff($user, 'finance')) {
            $source->whereHas('reconciliation.shop', fn ($q) => $q->where('user_id', $user->id));
        }
        $this->documentFilters($source, array_diff_key($filters, ['shop_id' => true]), 'paid_at');
        $source->when($filters['shop_id'] ?? null, fn ($q, $id) => $q->whereHas('reconciliation', fn ($q) => $q->where('shop_id', $id)));

        return DB::query()->fromSub($source->toBase(), 's')->join('reconciliations as r', 'r.id', '=', 's.reconciliation_id')
            ->join('shops', 'shops.id', '=', 'r.shop_id')->join('warehouses', 'warehouses.id', '=', 's.warehouse_id')
            ->selectRaw('s.id, s.reconciliation_id, r.status AS parent_status, s.code, s.paid_at AS occurred_at, s.status AS category, r.shop_id,
                s.warehouse_id, shops.name AS shop, warehouses.name AS warehouse, s.amount AS attempted,
                COALESCE(s.paid_amount, 0) AS paid, s.transaction_reference AS reference');
    }

    private function deposits(User $user, array $filters)
    {
        abort_unless($this->policy->staff($user, 'finance'), 403);
        $source = CashDeposit::visibleTo($user)->where('status', 'confirmed')->withSum('allocations as allocated_amount', 'amount');
        $this->documentFilters($source, $filters, 'received_at');

        return DB::query()->fromSub($source->toBase(), 's')->join('warehouses', 'warehouses.id', '=', 's.warehouse_id')
            ->join('users', 'users.id', '=', 's.shipper_id')
            ->selectRaw('s.id, s.code, s.received_at AS occurred_at, s.status AS category, s.warehouse_id,
                warehouses.name AS warehouse, users.name AS shipper, s.received_amount AS received,
                COALESCE(s.allocated_amount, 0) AS allocated, s.received_amount - COALESCE(s.allocated_amount, 0) AS unallocated');
    }

    private function documentFilters($query, array $filters, string $date): void
    {
        ReportPeriod::apply($query, $date, $filters);
        foreach (['warehouse_id', 'shop_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $id) => $q->where($field, $id));
        }
    }

    public function columns(string $ledger): array
    {
        $columns = ['code' => ['label' => 'Chứng từ / vận đơn', 'format' => 'text'],
            'occurred_at' => ['label' => 'Ngày phát sinh', 'format' => 'date'], 'warehouse' => ['label' => 'Kho', 'format' => 'text']];
        if ($ledger !== 'deposits') {
            $columns['shop'] = ['label' => 'Shop', 'format' => 'text'];
        }
        $columns['category'] = ['label' => 'Loại / trạng thái', 'format' => 'text'];
        if (in_array($ledger, ['collections', 'deposits'])) {
            $columns['shipper'] = ['label' => 'Shipper', 'format' => 'text'];
        }
        foreach ($this->moneyColumns($ledger) as $key => $label) {
            $columns[$key] = ['label' => $label, 'format' => 'money'];
        }
        if ($ledger === 'payments') {
            $columns['reference'] = ['label' => 'Mã ngân hàng', 'format' => 'text'];
        }

        return $columns;
    }

    public function moneyColumns(string $ledger): array
    {
        return match ($ledger) {
            'collections' => ['original' => 'Thu gốc', 'corrections' => 'Điều chỉnh', 'effective' => 'Thực thu hiệu lực',
                'deposited' => 'Đã nộp quỹ', 'held' => 'Shipper giữ', 'reserved' => 'Đã giữ đối soát', 'available' => 'Có thể đối soát'],
            'fees' => ['original' => 'Gốc nghĩa vụ', 'corrections' => 'Điều chỉnh', 'received' => 'Thu trực tiếp',
                'settled' => 'Đối soát đã duyệt', 'outstanding' => 'Còn nợ', 'available' => 'Khả dụng thu / đối soát'],
            'reconciliations' => ['cod' => 'COD', 'fees' => 'Phí', 'compensation' => 'Bồi thường',
                'payable' => 'Phải trả shop', 'paid' => 'Đã trả', 'remaining' => 'Còn phải trả', 'debt' => 'Nợ phí phát sinh'],
            'payments' => ['attempted' => 'Số tiền giao dịch', 'paid' => 'Chi thành công'],
            'deposits' => ['received' => 'Quỹ thực nhận', 'allocated' => 'Đã phân bổ', 'unallocated' => 'Chưa phân bổ'],
        };
    }

    public function summary($query, array $filters): array
    {
        $ledger = $filters['ledger'];
        $money = $this->moneyColumns($ledger);
        $aggregate = DB::query()->fromSub(clone $query, 'report_rows');
        foreach (array_keys($money) as $key) {
            $aggregate->selectRaw('COALESCE(SUM('.$key.'), 0) AS '.$key);
        }
        $totals = (array) $aggregate->first();
        $summary = [];
        foreach ($totals as $key => $value) {
            $summary[$key] = ['label' => $money[$key], 'value' => ReportValue::integer($value), 'format' => 'money'];
        }
        if ($ledger === 'collections') {
            $totals = DB::query()->fromSub(clone $query, 'report_rows')->where('category', 'cod')
                ->selectRaw('COALESCE(SUM(effective), 0) AS effective, COALESCE(SUM(held), 0) AS held,
                    COALESCE(SUM(deposited), 0) AS deposited, COALESCE(SUM(unreconciled), 0) AS unreconciled')->first();
            $summary = [];
            foreach (['effective' => 'COD thực thu', 'held' => 'COD shipper giữ', 'deposited' => 'COD đã nộp quỹ', 'unreconciled' => 'COD chưa vào đối soát'] as $key => $label) {
                $summary[$key] = ['label' => $label, 'value' => ReportValue::integer($totals->$key), 'format' => 'money'];
            }
        } elseif ($ledger === 'fees') {
            // No doi soat la nghia vu chuyen sang moi, khong phai doanh thu phi phat sinh lan hai.
            $accrued = DB::query()->fromSub(clone $query, 'report_rows')->where('category', '!=', 'reconciliation_debt')->sum('original');
            $summary['original'] = ['label' => 'Phí gốc phát sinh', 'value' => ReportValue::integer($accrued), 'format' => 'money'];
        }
        $groups = DB::query()->fromSub(clone $query, 'report_rows')->select([$filters['group_by'].'_id AS group_id', $filters['group_by'].' AS name', 'category']);
        foreach (array_keys($money) as $key) {
            $groups->selectRaw('SUM('.$key.') AS '.$key);
        }
        $groups = $groups->groupBy($filters['group_by'].'_id', $filters['group_by'], 'category')->orderBy('name')->get();

        return ['summary' => $summary, 'groups' => $groups, 'group_columns' => $money];
    }
}
