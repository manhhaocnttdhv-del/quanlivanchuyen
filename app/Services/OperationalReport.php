<?php

namespace App\Services;

use App\Models\User;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

class OperationalReport
{
    public const METRICS = [
        'created' => 'Đơn tạo trong kỳ', 'received' => 'Đơn gửi duyệt trong kỳ',
        'delivered' => 'Giao thành công', 'returned' => 'Hoàn thành công', 'failed' => 'Lượt giao thất bại',
        'inventory' => 'Đơn đang tồn kho', 'overdue' => 'Đơn xử lý quá hạn',
    ];

    public function __construct(private ReportScope $scope) {}

    public function query(User $user, array $filters, ?string $metric = null)
    {
        $metric ??= $filters['metric'];
        $query = $this->scope->orders($user, 'operations');
        $event = in_array($metric, ['delivered', 'returned', 'failed']);
        $shipper = $event ? 'event.shipper_id' : 'orders.assigned_shipper_id';
        $date = match ($metric) {
            'created' => 'orders.created_at', 'received' => 'orders.submitted_at',
            'inventory', 'overdue' => 'orders.approved_at', default => 'event.attempted_at',
        };
        if ($event) {
            // Lay shipper tren lan giao that; khong quy ket qua cu cho nguoi duoc gan lai sau do.
            $query->join('delivery_attempts as event', 'event.order_id', '=', 'orders.id')
                ->where('event.type', $metric === 'returned' ? 'return' : 'delivery')
                ->where('event.outcome', $metric === 'failed' ? 'failed' : 'success');
        } elseif ($metric === 'inventory') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('warehouse_items')
                ->whereColumn('warehouse_items.order_id', 'orders.id')->where('holder_type', 'warehouse'));
        } elseif ($metric === 'overdue') {
            $query->whereNotIn('orders.status', ['delivered', 'returned', 'cancelled', 'rejected'])
                ->where('orders.approved_at', '<', now()->subHours(config('reports.overdue_hours')));
        }
        // Ton va qua han la hien tai; cac chi tieu con lai dua tren ngay su kien trong ky.
        if (! in_array($metric, ['inventory', 'overdue'])) {
            ReportPeriod::apply($query, $date, $filters);
        }
        $this->scope->filterOrders($query, $filters, $shipper);

        return $query->leftJoin('shops', 'shops.id', '=', 'orders.shop_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'orders.warehouse_id')
            ->leftJoin('areas', 'areas.id', '=', 'orders.area_id')
            ->leftJoin('users as report_shipper', 'report_shipper.id', '=', $shipper)
            ->select(['orders.id', 'orders.id as order_id', 'orders.tracking_code as code', 'orders.status as category',
                'orders.shop_id', 'orders.warehouse_id', 'orders.area_id',
                'shops.name as shop', 'warehouses.name as warehouse', 'areas.name as area', 'report_shipper.name as shipper'])
            ->selectRaw($shipper.' AS shipper_id')
            ->selectRaw($date.' AS occurred_at');
    }

    public function summary(User $user, array $filters): array
    {
        $summary = [];
        $groups = collect();
        foreach (self::METRICS as $metric => $label) {
            $query = $this->query($user, $filters, $metric)->toBase();
            $summary[$metric] = ['label' => $label, 'value' => (clone $query)->count(), 'format' => 'number'];
            $counts = DB::query()->fromSub($query, 'activity')->select([$filters['group_by'].'_id as group_id', $filters['group_by'].' as name'])
                ->selectRaw('COUNT(*) as total')->groupBy($filters['group_by'].'_id', $filters['group_by'])->get();
            foreach ($counts as $row) {
                $key = $row->group_id ?? 'none';
                $group = $groups->get($key, ['group_id' => $row->group_id, 'name' => $row->name ?? 'Chưa phân công'] + array_fill_keys(array_keys(self::METRICS), 0));
                $group[$metric] = (int) $row->total;
                $groups->put($key, $group);
            }
        }
        $attempts = $summary['delivered']['value'] + $summary['failed']['value'];
        $summary['success_rate'] = ['label' => 'Tỷ lệ lượt giao thành công', 'value' => $attempts ? round($summary['delivered']['value'] * 100 / $attempts, 1) : null, 'format' => 'percent',
            'detail' => $summary['delivered']['value'].' / '.$attempts.' lượt giao trong kỳ'];

        return ['summary' => $summary, 'groups' => $groups->sortBy('name')->values(), 'group_columns' => self::METRICS];
    }

    public function columns(): array
    {
        return ['code' => ['label' => 'Vận đơn', 'format' => 'text'], 'occurred_at' => ['label' => 'Thời điểm', 'format' => 'date'],
            'shop' => ['label' => 'Shop', 'format' => 'text'], 'warehouse' => ['label' => 'Kho', 'format' => 'text'],
            'area' => ['label' => 'Khu vực', 'format' => 'text'], 'shipper' => ['label' => 'Shipper', 'format' => 'text'],
            'category' => ['label' => 'Trạng thái hiện tại', 'format' => 'text']];
    }
}
