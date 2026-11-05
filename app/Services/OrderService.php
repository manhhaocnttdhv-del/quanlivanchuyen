<?php

namespace App\Services;

use App\Models\Area;
use App\Models\DeliveryTask;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrderRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private FeeCalculator $fees, private AuditService $audit) {}

    // Cùng thứ tự khóa shop -> order -> change ở mọi luồng để hạn chế deadlock.
    public function saveDraft(User $actor, array $data, ?Order $order = null): Order
    {
        return DB::transaction(function () use ($actor, $data, $order) {
            $shop = $this->lockShop($actor->shop()->firstOrFail()->id);
            $details = $this->validateDetails($shop, $data, false);
            if (! $order) {
                Gate::forUser($actor)->authorize('create', Order::class);
                $hash = hash('sha256', json_encode($details, JSON_THROW_ON_ERROR));
                $existing = Order::where('shop_id', $shop->id)->where('client_token', $data['client_token'])->first();
                if ($existing) {
                    $this->ensure(hash_equals($existing->creation_hash, $hash), 'client_token', 'Lần gửi này đã lưu với nội dung khác. Mở đơn đã tạo hoặc tải lại form.');

                    return $existing;
                }
                $order = new Order(['shop_id' => $shop->id, 'tracking_code' => 'GV'.strtoupper((string) Str::ulid()),
                    'client_token' => $data['client_token'], 'creation_hash' => $hash, 'created_by' => $actor->id, 'status' => 'draft', 'revision' => 1]);
                $before = [];
                $event = 'created';
            } else {
                $order = Order::lockForUpdate()->findOrFail($order->id);
                Gate::forUser($actor)->authorize('update', $order);
                $this->checkRevision($order, (int) $data['revision']);
                $this->ensure(in_array($order->status, Order::EDITABLE), 'status', 'Trạng thái này không được sửa trực tiếp.');
                $before = $this->snapshot($order);
                $order->revision++;
                $event = 'updated';
            }
            $this->fillDetails($order, $details);
            $this->clearQuote($order);
            $order->save();
            $this->replaceItems($order, $details['items']);
            $this->history($actor, $order, $event, $before, 'Lưu thông tin bản nháp');

            return $order->fresh();
        }, 3);
    }

    public function preview(Order $order): array
    {
        $details = $this->validateDetails($order->shop, $order->inputData(), true);

        return $this->quoteDetails($order->shop, $details);
    }

    public function submit(User $actor, Order $order, int $revision): void
    {
        DB::transaction(function () use ($actor, $order, $revision) {
            $shop = $this->lockShop($order->shop_id);
            $order = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $order);
            $this->checkRevision($order, $revision);
            $this->ensure(in_array($order->status, Order::EDITABLE), 'status', 'Chỉ gửi đơn nháp, cần bổ sung hoặc bị từ chối.');
            $this->approvedShop($shop);
            $before = $this->snapshot($order);
            $details = $this->validateDetails($shop, $order->inputData(), true);
            $this->applyQuote($order, $shop, $details);
            $order->status = 'submitted';
            $order->submitted_at = now();
            $order->approved_at = null;
            $order->approved_by = null;
            $order->revision++;
            $order->save();
            $this->history($actor, $order, 'submitted', $before, 'Shop xác nhận thông tin và gửi duyệt');
        }, 3);
    }

    public function review(User $actor, Order $order, int $revision, string $decision, string $reason): void
    {
        DB::transaction(function () use ($actor, $order, $revision, $decision, $reason) {
            $shop = $this->lockShop($order->shop_id);
            $order = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('review', $order);
            $this->ensure($order->created_by !== $actor->id, 'reason', 'Người tạo vận đơn không được tự duyệt.');
            $this->checkRevision($order, $revision);
            $this->ensure($order->status === 'submitted' && in_array($decision, ['approved', 'needs_info', 'rejected']), 'status', 'Chỉ xét duyệt đơn đang chờ duyệt.');
            if ($decision === 'approved') {
                $this->approvedShop($shop);
            }
            $before = $this->snapshot($order);
            $order->status = $decision;
            $order->approved_by = $decision === 'approved' ? $actor->id : null;
            $order->approved_at = $decision === 'approved' ? now() : null;
            $order->revision++;
            $order->save();
            $this->history($actor, $order, 'reviewed', $before, $reason);
        }, 3);
    }

    public function cancel(User $actor, Order $order, int $revision, string $reason): void
    {
        DB::transaction(function () use ($actor, $order, $revision, $reason) {
            $this->lockShop($order->shop_id);
            $order = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $order);
            $this->checkRevision($order, $revision);
            $this->ensure(in_array($order->status, [...Order::EDITABLE, 'submitted']) && $order->custody_status === 'uncollected', 'status', 'Không thể hủy trực tiếp. Đơn đã duyệt cần đề nghị; kiện đã lấy cần thu hồi.');
            $before = $this->snapshot($order);
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $order->revision++;
            $order->save();
            $this->history($actor, $order, 'cancelled', $before, $reason);
        }, 3);
    }

    public function requestChange(User $actor, Order $order, int $revision, string $type, array $data, string $reason): void
    {
        DB::transaction(function () use ($actor, $order, $revision, $type, $data, $reason) {
            $shop = $this->lockShop($order->shop_id);
            $order = Order::lockForUpdate()->findOrFail($order->id);
            Gate::forUser($actor)->authorize('update', $order);
            $this->checkRevision($order, $revision);
            $this->changeable($order);
            $this->ensure(in_array($type, ['edit', 'cancel']), 'type', 'Loại đề nghị không hợp lệ.');
            $details = $type === 'edit' ? $this->validateDetails($shop, $data, true) : null;
            if ($details) {
                $this->approvedShop($shop);
                $this->quoteDetails($shop, $details);
            }
            $before = $this->snapshot($order);
            $order->revision++;
            $order->save();
            $change = $order->changeRequests()->create(['type' => $type, 'created_by' => $actor->id, 'order_revision' => $order->revision,
                'changes' => $details, 'before_values' => $before, 'reason' => $reason]);
            $this->history($actor, $order, 'change_requested', $before, $reason);
            $this->audit->record($actor, $change, 'order.change_requested', [], ['type' => $type, 'changes' => $details], $reason);
        }, 3);
    }

    public function reviewChange(User $actor, OrderChangeRequest $change, string $decision, string $reason): void
    {
        DB::transaction(function () use ($actor, $change, $decision, $reason) {
            $shop = $this->lockShop($change->order->shop_id);
            $order = Order::lockForUpdate()->findOrFail($change->order_id);
            $change = OrderChangeRequest::lockForUpdate()->findOrFail($change->id);
            Gate::forUser($actor)->authorize('review', $order);
            $this->ensure($change->created_by !== $actor->id, 'reason', 'Người tạo đề nghị không được tự duyệt.');
            $this->ensure($change->status === 'pending' && in_array($decision, ['approved', 'rejected']), 'reason', 'Đề nghị đã xử lý hoặc kết quả không hợp lệ.');
            // Từ chối không áp dụng dữ liệu cũ; vẫn cho đóng đề nghị khi kho vừa ghi sự kiện mới.
            if ($decision === 'approved') {
                $this->checkRevision($order, $change->order_revision);
            }
            $before = $this->snapshot($order);
            if ($decision === 'approved') {
                if ($change->type === 'measured_weight') {
                    $this->applyMeasuredWeight($order, $change);
                } else {
                    $this->ensure($order->status === 'approved' && $order->custody_status === 'uncollected', 'status', 'Kiện đã xử lý vận chuyển; cần quy trình thu hồi trước.');
                    $this->ensure(! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists(), 'status', 'Thu hồi nhiệm vụ đang hiệu lực trước khi chấp nhận thay đổi.');
                    if ($change->type === 'cancel') {
                        $order->status = 'cancelled';
                        $order->cancelled_at = now();
                    } else {
                        $this->approvedShop($shop);
                        // Đề nghị cũ chưa có lựa chọn kho vẫn dùng kho đã lưu trên đơn.
                        $details = $this->validateDetails($shop, array_merge(['warehouse_id' => $order->warehouse_id], $change->changes), true);
                        $this->fillDetails($order, $details);
                        $this->applyQuote($order, $shop, $details);
                        $this->replaceItems($order, $details['items']);
                        // Mọi sửa sau duyệt đều xét duyệt lại, kể cả khi chỉ đổi thông tin người nhận.
                        $order->status = 'submitted';
                        $order->submitted_at = now();
                        $order->approved_at = null;
                        $order->approved_by = null;
                    }
                }
            }
            $order->revision++;
            $order->save();
            $change->update(['status' => $decision, 'reviewed_by' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => now()]);
            $this->history($actor, $order, $decision === 'approved' ? 'change_applied' : 'change_rejected', $before, $reason);
            $this->audit->record($actor, $change, 'order.change_reviewed', ['status' => 'pending'], ['status' => $decision], $reason);
        }, 3);
    }

    private function applyMeasuredWeight(Order $order, OrderChangeRequest $change): void
    {
        $this->ensure($order->status === 'at_warehouse' && $order->custody_status === 'warehouse' && ! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists(), 'status', 'Cần đủ kiện tại kho và không có nhiệm vụ hiệu lực.');
        $grams = (int) $order->parcels()->sum('actual_weight_grams');
        $this->ensure($grams === (int) $change->changes['weight_grams'], 'reason', 'Kết quả cân đã thay đổi; lập đề nghị mới.');
        $this->ensure($order->discrepancies()->whereKey($change->changes['discrepancy_id'])->where('type', 'weight')->where('status', 'open')->exists(), 'reason', 'Sai lệch cân không còn mở.');
        // Tái tính ở server theo biểu phí hiệu lực; phí cũ được lưu trong đề nghị và history.
        $quote = $this->fees->calculate(Area::findOrFail($order->origin_area_id), Area::findOrFail($order->area_id), $grams, $order->service, (int) $order->cod_amount, $order->fee_payer);
        $order->weight_grams = $grams;
        $order->weight_kg = intdiv($grams, 1000).'.'.str_pad((string) ($grams % 1000), 3, '0', STR_PAD_LEFT);
        $order->shipping_fee = $quote['total'];
        $order->recipient_total = $quote['recipient_total'];
        $order->fee_snapshot = $quote;
        $order->fee_version_id = $quote['version_id'];
        $order->fee_rule_id = $quote['rule_id'];
    }

    private function validateDetails(Shop $shop, array $data, bool $complete): array
    {
        $data = Validator::make($data, OrderRules::details($complete), OrderRules::messages(), OrderRules::attributes())->validate();
        $pickup = null;
        if (! empty($data['pickup_address_id'])) {
            $pickup = $shop->addresses()->with('area')->find($data['pickup_address_id']);
            $this->ensure($pickup && $pickup->is_active && $pickup->area->is_active, 'pickup_address_id', 'Chọn địa chỉ lấy hàng đang hoạt động của chính bạn.');
        }
        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)
                ->whereHas('area', fn ($query) => $query->where('is_active', true))->lockForUpdate()->first();
            $this->ensure((bool) $warehouse, 'warehouse_id', 'Chọn kho tiếp nhận đang hoạt động.');
            $this->ensure(! $pickup || $warehouse->area_id === $pickup->area_id, 'warehouse_id', 'Kho tiếp nhận phải thuộc khu vực lấy hàng.');
        }
        if (! empty($data['area_id'])) {
            $this->ensure(Area::whereKey($data['area_id'])->where('is_active', true)->exists(), 'area_id', 'Khu vực nhận đang ngừng phục vụ.');
        }
        foreach (['pickup_address_id', 'warehouse_id', 'area_id', 'weight_grams', 'parcel_count', 'cod_amount'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        $data['items'] = array_map(fn ($item) => ['name' => $item['name'], 'quantity' => (int) $item['quantity']], $data['items'] ?? []);

        return array_merge(array_fill_keys(Order::INPUT_FIELDS, null), $data);
    }

    private function quoteDetails(Shop $shop, array $details): array
    {
        $pickup = $shop->addresses()->with('area')->findOrFail($details['pickup_address_id']);

        return $this->fees->calculate($pickup->area, Area::findOrFail($details['area_id']), $details['weight_grams'], $details['service'], $details['cod_amount'], $details['fee_payer']);
    }

    private function applyQuote(Order $order, Shop $shop, array $details): void
    {
        $pickup = $shop->addresses()->with('area')->lockForUpdate()->findOrFail($details['pickup_address_id']);
        $destination = Area::findOrFail($details['area_id']);
        $quote = $this->fees->calculate($pickup->area, $destination, $details['weight_grams'], $details['service'], $details['cod_amount'], $details['fee_payer']);
        $order->origin_area_id = $pickup->area_id;
        $order->warehouse_id = $details['warehouse_id'];
        $order->origin_warehouse_id = $details['warehouse_id'];
        $order->sender_snapshot = ['name' => $pickup->contact_name, 'phone' => $pickup->phone, 'address' => $pickup->address, 'area' => $pickup->area->name, 'shop' => $shop->name];
        $order->recipient_snapshot = ['name' => $details['recipient_name'], 'phone' => $details['recipient_phone'], 'address' => $details['recipient_address'], 'area' => $destination->name];
        $order->fee_snapshot = $quote;
        $order->fee_version_id = $quote['version_id'];
        $order->fee_rule_id = $quote['rule_id'];
        $order->shipping_fee = $quote['total'];
        $order->recipient_total = $quote['recipient_total'];
    }

    private function fillDetails(Order $order, array $details): void
    {
        $order->fill(Arr::only($details, Order::INPUT_FIELDS));
        $order->origin_warehouse_id = $details['warehouse_id'];
        $grams = $details['weight_grams'] ?? null;
        $order->weight_kg = $grams ? intdiv($grams, 1000).'.'.str_pad((string) ($grams % 1000), 3, '0', STR_PAD_LEFT) : null;
    }

    private function replaceItems(Order $order, array $items): void
    {
        $order->items()->delete();
        $order->items()->createMany($items);
        $order->unsetRelation('items');
    }

    private function clearQuote(Order $order): void
    {
        $order->fill(array_fill_keys(['shipping_fee', 'recipient_total', 'fee_version_id', 'fee_rule_id', 'sender_snapshot', 'recipient_snapshot', 'fee_snapshot', 'origin_area_id'], null));
    }

    private function lockShop(int $id): Shop
    {
        return Shop::lockForUpdate()->findOrFail($id);
    }

    private function approvedShop(Shop $shop): void
    {
        $this->ensure($shop->status === 'approved', 'status', 'Hồ sơ người gửi phải được duyệt trước khi gửi hoặc thay đổi đơn.');
    }

    private function checkRevision(Order $order, int $revision): void
    {
        $this->ensure($order->revision === $revision, 'revision', 'Đơn đã thay đổi ở phiên khác. Tải lại trang trước khi tiếp tục.');
    }

    private function changeable(Order $order): void
    {
        $this->ensure(! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists(), 'status', 'Liên hệ điều phối thu hồi nhiệm vụ trước khi đề nghị thay đổi.');
        $this->ensure($order->status === 'approved' && $order->custody_status === 'uncollected', 'status', 'Chỉ đề nghị khi đơn đã duyệt và chưa lấy kiện. Đơn đã giao không được hủy; kiện đang giữ cần thu hồi.');
        $this->ensure(! $order->pendingChange()->exists(), 'reason', 'Đã có đề nghị đang chờ xử lý.');
    }

    private function snapshot(Order $order): array
    {
        return array_merge($order->inputData(), $order->only(['status', 'tracking_code', 'revision', 'shipping_fee', 'recipient_total', 'warehouse_id', 'fee_version_id', 'sender_snapshot', 'recipient_snapshot', 'fee_snapshot']));
    }

    private function history(User $actor, Order $order, string $event, array $before, string $reason): void
    {
        $after = $this->snapshot($order);
        $order->histories()->create(['created_by' => $actor->id, 'event' => $event, 'from_status' => $before['status'] ?? null,
            'to_status' => $order->status, 'revision' => $order->revision, 'before_values' => $before, 'after_values' => $after, 'reason' => $reason, 'created_at' => now()]);
        $this->audit->record($actor, $order, 'order.'.$event, $before, $after, $reason);
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
