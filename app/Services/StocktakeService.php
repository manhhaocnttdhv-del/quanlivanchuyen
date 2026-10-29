<?php

namespace App\Services;

use App\Models\Discrepancy;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseLocation;
use App\Policies\LogisticsPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StocktakeService
{
    public function __construct(private LogisticsLedger $ledger, private LogisticsPolicy $policy, private AuditService $audit) {}

    public function start(User $actor, array $data): Stocktake
    {
        return DB::transaction(function () use ($actor, $data) {
            abort_unless($this->policy->warehouse($actor, (int) $data['warehouse_id']), 403);
            if (! empty($data['location_id'])) {
                $this->ledger->ensure(WarehouseLocation::whereKey($data['location_id'])->where('warehouse_id', $data['warehouse_id'])->exists(), 'Vị trí phải thuộc kho kiểm kê.', 'location_id');
            }
            $stocktake = Stocktake::create(['code' => 'KK'.Str::upper((string) Str::ulid()), 'warehouse_id' => $data['warehouse_id'], 'warehouse_location_id' => $data['location_id'] ?? null,
                'created_by' => $actor->id, 'reason' => $data['reason'], 'snapshot_at' => now()]);
            // Một SELECT tạo snapshot nhất quán. Không khóa kho suốt thời gian người dùng đếm.
            $items = $this->inventory($stocktake)->with('location')->orderBy('id')->get();
            foreach ($items as $item) {
                $stocktake->lines()->create(['warehouse_item_id' => $item->id, 'expected_version' => $item->custody_version, 'location_snapshot' => $item->location?->code]);
            }
            $this->audit->record($actor, $stocktake, 'stocktake.started', [], ['status' => 'draft', 'parcel_count' => $items->count(), 'warehouse_id' => $stocktake->warehouse_id], $data['reason']);

            return $stocktake;
        }, 3);
    }

    public function submit(User $actor, Stocktake $stocktake, array $data): void
    {
        DB::transaction(function () use ($actor, $stocktake, $data) {
            $stocktake = Stocktake::lockForUpdate()->findOrFail($stocktake->id);
            abort_unless($this->policy->warehouse($actor, $stocktake->warehouse_id) && $stocktake->created_by === $actor->id, 403);
            $this->ledger->ensure($stocktake->status === 'draft', 'Biên bản đã gửi, không thể sửa kiểm đếm.');
            $provided = collect($data['lines'] ?? []);
            $lines = $stocktake->lines()->whereIn('id', $provided->keys())->get();
            $this->ledger->ensure($provided->count() === $lines->count(), 'Danh sách có dòng không thuộc biên bản.', 'lines');
            foreach ($lines as $line) {
                $input = $data['lines'][$line->id];
                $this->ledger->ensure((bool) $input['actual_present'] || ! empty($input['note']), 'Kiện không tìm thấy phải có ghi chú.', 'lines.'.$line->id.'.note');
                $line->update(['actual_present' => (bool) $input['actual_present'], 'note' => $input['note'] ?? null]);
            }
            $stocktake->unexpected_note = $data['unexpected_note'] ?? $stocktake->unexpected_note;
            if (! ($data['finalize'] ?? true)) {
                $stocktake->save();
                $this->audit->record($actor, $stocktake, 'stocktake.count_saved', [], ['parcel_count' => $lines->count()], 'Lưu kết quả đếm từng trang');

                return;
            }
            $this->ledger->ensure(! $stocktake->lines()->whereNull('actual_present')->exists(), 'Chưa đếm đủ snapshot. Lưu từng trang trước khi gửi biên bản.', 'lines');
            $stocktake->status = 'submitted';
            $stocktake->save();
            $this->audit->record($actor, $stocktake, 'stocktake.submitted', ['status' => 'draft'], ['status' => 'submitted'], $stocktake->reason);
        }, 3);
    }

    public function approve(User $actor, Stocktake $stocktake, string $reason): void
    {
        DB::transaction(function () use ($actor, $stocktake, $reason) {
            $stocktake = Stocktake::lockForUpdate()->findOrFail($stocktake->id);
            abort_unless($this->policy->approve($actor, $stocktake->warehouse_id), 403);
            $this->ledger->ensure($stocktake->status === 'submitted' && $stocktake->created_by !== $actor->id, 'Cần biên bản đã gửi và người duyệt khác người lập.');
            $lines = $stocktake->lines()->with('item.order')->get();
            $current = $this->inventory($stocktake)->with('order')->get();
            $items = $lines->pluck('item')->merge($current)->unique('id');
            $orders = $items->pluck('order')->unique('id')->sortBy(fn ($order) => sprintf('%020d-%020d', $order->shop_id, $order->id));
            $locked = [];
            foreach ($orders as $order) {
                $locked[$order->id] = $this->ledger->lock($order);
            }
            // Locking read lấy phiên bản mới nhất, kể cả giao dịch đã chờ một lần xuất kho.
            $current = $this->inventory($stocktake)->whereIn('order_id', array_keys($locked))->lockForUpdate()->get();
            $affected = [];
            foreach ($lines as $line) {
                $item = WarehouseItem::lockForUpdate()->findOrFail($line->warehouse_item_id);
                $moved = $item->custody_version !== $line->expected_version;
                $line->update(['movement_detected' => $moved]);
                if ($moved || ! $line->actual_present) {
                    $type = $moved ? 'stock_movement' : 'stock_missing';
                    $affected[$item->order_id] = true;
                    $this->difference($stocktake, $item, $type, $line->note ?: 'Kiện biến động sau thời điểm snapshot.', ! $moved);
                }
            }
            foreach ($current->whereNotIn('id', $lines->pluck('warehouse_item_id')) as $item) {
                $this->difference($stocktake, $item, 'stock_movement', 'Kiện nhập vào vị trí sau snapshot.', false);
                $affected[$item->order_id] = true;
            }
            if ($stocktake->unexpected_note) {
                $this->difference($stocktake, null, 'stock_extra', $stocktake->unexpected_note, false);
            }
            $stocktake->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_reason' => $reason]);
            foreach (array_keys($affected) as $id) {
                $order = $locked[$id];
                $this->ledger->record($actor, $order, 'stocktake_difference', $this->ledger->snapshot($order), $stocktake->code.': '.$reason);
            }
            // Chỉ ghi biên bản và hold. Không xóa/thêm tồn để làm số kiểm kê khớp sổ.
            $this->audit->record($actor, $stocktake, 'stocktake.approved', ['status' => 'submitted'], ['status' => 'approved', 'approved_by' => $actor->id], $reason);
        }, 3);
    }

    private function inventory(Stocktake $stocktake)
    {
        return WarehouseItem::where('warehouse_id', $stocktake->warehouse_id)->where('holder_type', 'warehouse')
            ->when($stocktake->warehouse_location_id, fn ($q, $id) => $q->where('warehouse_location_id', $id));
    }

    private function difference(Stocktake $stocktake, ?WarehouseItem $item, string $type, string $reason, bool $blocks): void
    {
        Discrepancy::create(['warehouse_id' => $stocktake->warehouse_id, 'order_id' => $item?->order_id, 'warehouse_item_id' => $item?->id, 'stocktake_id' => $stocktake->id,
            'created_by' => $stocktake->created_by, 'type' => $type, 'severity' => $blocks ? 'high' : 'medium', 'blocks_dispatch' => $blocks, 'reason' => $reason]);
    }
}
