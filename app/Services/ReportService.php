<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\DeliveryCollection;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\User;
use App\Policies\ReportPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public const GROUP_LABELS = ['warehouse' => 'Kho', 'shop' => 'Shop', 'area' => 'Khu vực', 'shipper' => 'Shipper'];

    public function __construct(private OperationalReport $operations, private FinancialReport $finance, private ReportScope $scope, private ReportPolicy $policy) {}

    public function query(User $user, string $type, array $filters)
    {
        abort_unless($this->policy->view($user, $type), 403);
        abort_unless(in_array($filters['group_by'], ['warehouse', 'shop', 'area', 'shipper']), 422);

        return $type === 'operations' ? $this->operations->query($user, $filters)->toBase() : $this->finance->query($user, $filters);
    }

    public function build(User $user, string $type, array $filters, ?int $exportLimit = null): array
    {
        $this->scope->validate($user, $type, $filters);

        // Cung mot read transaction: tong, nhom va chi tiet nhin cung snapshot MySQL.
        return DB::transaction(function () use ($user, $type, $filters, $exportLimit) {
            $query = $this->query($user, $type, $filters);
            $summary = $type === 'operations' ? $this->operations->summary($user, $filters) : $this->finance->summary($query, $filters);
            $query->orderByDesc('occurred_at')->orderByDesc('id');
            $rows = $exportLimit === null ? $query->paginate(25)->withQueryString() : $query->limit($exportLimit + 1)->get();
            if ($exportLimit !== null && $rows->count() > $exportLimit) {
                throw ValidationException::withMessages(['format' => 'Quá nhiều dòng để xuất. Hãy thu hẹp bộ lọc.']);
            }
            $categoryLabels = $this->categoryLabels($type, $filters['ledger']);
            $canOpenOrders = $user->hasRole('admin') || $user->shop !== null || $user->hasPermission('orders.review');
            $staff = $type === 'finance' && $this->policy->staff($user, 'finance');
            foreach ($rows as $row) {
                $row->category_label = $categoryLabels[$row->category] ?? $row->category;
                $row->source_url = $this->sourceUrl($type, $filters['ledger'], $row, $canOpenOrders, $staff);
            }
            $groups = $summary['groups']->map(fn ($row) => (array) $row)->map(function ($row) use ($categoryLabels) {
                if (isset($row['category'])) {
                    $row['category_label'] = $categoryLabels[$row['category']] ?? $row['category'];
                }

                return $row;
            });
            $detailTitle = $type === 'operations' ? OperationalReport::METRICS[$filters['metric']] : FinancialReport::LEDGERS[$filters['ledger']];
            $labels = ['detail' => 'Chi tiết: '.$detailTitle, 'group_by' => 'Nhóm: '.self::GROUP_LABELS[$filters['group_by']]];
            foreach ($this->scope->choices($user, $type) as $field => $choices) {
                if (! empty($filters[$field])) {
                    $fieldLabel = self::GROUP_LABELS[str_replace('_id', '', $field)];
                    $labels[$field] = $fieldLabel.': '.$choices->get($filters[$field]);
                }
            }

            return array_merge($summary, ['type' => $type, 'filters' => $filters, 'filter_labels' => $labels, 'rows' => $rows,
                'groups' => $groups, 'as_of' => now()->format('Y-m-d H:i:s'),
                'title' => $type === 'operations' ? 'Báo cáo vận hành' : 'Báo cáo '.FinancialReport::LEDGERS[$filters['ledger']],
                'detail_title' => $detailTitle,
                'columns' => $type === 'operations' ? $this->operations->columns() : $this->finance->columns($filters['ledger']),
                'period_note' => $this->periodNote($type, $filters),
            ]);
        });
    }

    public function categoryLabels(string $type, string $ledger): array
    {
        return $type === 'operations' ? Order::STATUSES : match ($ledger) {
            'collections' => DeliveryCollection::TYPES, 'fees' => Receivable::TYPES,
            'reconciliations' => Reconciliation::STATUSES, 'payments' => Payment::STATUSES, 'deposits' => CashDeposit::STATUSES,
        };
    }

    private function sourceUrl(string $type, string $ledger, object $row, bool $canOpenOrders, bool $staff): ?string
    {
        if ($type === 'operations') {
            return $canOpenOrders ? route('orders.show', $row->order_id) : null;
        }
        if ($ledger === 'reconciliations' && ! $staff && $row->category !== 'approved') {
            return null;
        }
        if ($ledger === 'payments' && ! $staff && ! in_array($row->parent_status, ['submitted', 'approved'])) {
            return null;
        }

        return match ($ledger) {
            'collections' => route('finance.collections.show', $row->id), 'fees' => route('finance.fees.show', $row->id),
            'reconciliations' => route('finance.reconciliations.show', $row->id),
            'payments' => route('finance.reconciliations.show', $row->reconciliation_id),
            'deposits' => route('finance.deposits.show', $row->id),
        };
    }

    private function periodNote(string $type, array $filters): string
    {
        if ($type === 'operations') {
            return in_array($filters['metric'], ['inventory', 'overdue'])
                ? 'Tồn kho và quá hạn tại thời điểm hiện tại. Ngưỡng quá hạn: '.config('reports.overdue_hours').' giờ từ lúc duyệt.'
                : 'Ngày tạo / gửi đơn và ngày thực hiện giao, hoàn được thống kê riêng. Tồn kho và quá hạn là số hiện tại.';
        }

        return match ($filters['ledger']) {
            'collections' => 'Khoản thu phát sinh trong kỳ; phân bổ, điều chỉnh và số dư hiện tại. COD tách riêng phí.',
            'fees' => 'Nghĩa vụ phát sinh trong kỳ; số nợ hiện tại. Nợ sau đối soát không tính là phí phát sinh lần hai.',
            'reconciliations' => 'Đối soát được lập trong kỳ và đã được duyệt ít nhất một lần; số còn phải trả hiện tại.',
            'payments' => 'Ngày giao dịch ngân hàng trong kỳ. Chỉ chi thành công mới giảm số phải trả.',
            'deposits' => 'Ngày quỹ thực nhận trong kỳ; tiền chưa phân bổ giữ riêng, không tự ghi doanh thu.',
        };
    }
}
