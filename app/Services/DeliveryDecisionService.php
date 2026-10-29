<?php

namespace App\Services;

use App\Models\DeliveryDecision;
use App\Models\DeliveryTask;
use App\Models\Order;
use App\Models\ReturnRecord;
use App\Models\User;
use App\Policies\LogisticsPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryDecisionService
{
    public function __construct(private LogisticsLedger $ledger, private LogisticsPolicy $policy, private AuditService $audit) {}

    public function decide(User $actor, Order $order, array $data): void
    {
        $data = Validator::make($data, ['decision' => ['required', Rule::in(array_keys(DeliveryDecision::TYPES))], 'revision' => ['sometimes', 'integer', 'min:1'],
            'shop_instruction' => ['required', 'string', 'min:5', 'max:1000'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'fee_payment' => ['sometimes', Rule::in(array_keys(ReturnRecord::PAYMENTS))]])->validate();
        DB::transaction(function () use ($actor, $order, $data) {
            $order = $this->ledger->lock($order);
            abort_unless($this->policy->dispatch($actor, $order->warehouse_id), 403);
            $this->ledger->ensure(! isset($data['revision']) || $order->revision === (int) $data['revision'], 'Đơn đã thay đổi; tải lại trước khi quyết định.', 'revision');
            $this->ledger->ensure($order->status === 'at_warehouse' && $order->custody_status === 'warehouse' && $order->parcels()->where('holder_type', 'warehouse')->where('warehouse_id', $order->warehouse_id)->count() === $order->parcel_count, 'Phải nhận đủ kiện về kho trước khi quyết định giao lại/hoàn.');
            $this->ledger->ensure(! $order->tasks()->whereIn('status', DeliveryTask::ACTIVE)->exists() && ! $order->pendingChange()->exists(), 'Nhiệm vụ cũ hoặc đề nghị thay đổi chưa kết thúc.');
            $decision = $data['decision'];
            $this->ledger->ensure(isset(DeliveryDecision::TYPES[$decision]) && $order->return_authorized_by === null, 'Quyết định không hợp lệ hoặc đơn đã chuyển hoàn.');
            $before = $this->ledger->snapshot($order);
            if ($decision === 'return') {
                $order->return_authorized_by = $actor->id;
                $order->return_reason = $data['reason'];
                $order->retry_authorized_attempt = null;
                $this->ensureReturnRecord($actor, $order, $data['fee_payment'] ?? 'reconciliation');
            } else {
                $this->ledger->ensureNoIncidentDispatchHold($order);
                $last = $order->deliveryAttempts()->where('type', 'delivery')->latest('attempt_number')->first();
                $this->ledger->ensure($last && $last->outcome === 'failed' && $order->retry_authorized_attempt === null, 'Chỉ quyết định giao lại sau lần giao thất bại chưa được xử lý.');
                if ($decision === 'retry_exception') {
                    abort_unless($this->policy->approve($actor, $order->warehouse_id), 403);
                    $this->ledger->ensure($order->delivery_attempt_count >= $order->delivery_attempt_limit && $order->delivery_attempt_count < 100, 'Chỉ cho phép thêm một lượt khi đã chạm giới hạn (tối đa 100 lượt).');
                    $order->delivery_attempt_limit = $order->delivery_attempt_count + 1;
                } else {
                    $this->ledger->ensure($order->delivery_attempt_count < $order->delivery_attempt_limit, 'Đã đủ số lượt. Cần quyết định ngoại lệ hoặc hoàn hàng.');
                }
                $order->retry_authorized_attempt = $order->delivery_attempt_count + 1;
            }
            DeliveryDecision::create(['order_id' => $order->id, 'created_by' => $actor->id, 'decision' => $decision, 'attempt_count' => $order->delivery_attempt_count,
                'authorized_attempt' => $order->retry_authorized_attempt, 'shop_instruction' => $data['shop_instruction'], 'reason' => $data['reason']]);
            $this->ledger->record($actor, $order, $decision === 'return' ? 'return_authorized' : 'retry_authorized', $before, $data['reason'].' · Shop: '.$data['shop_instruction']);
        }, 3);
    }

    // Đơn có quyết định hoàn từ Sprint 03 vẫn cần một hồ sơ phí cố định, không tính lại theo giá mới.
    public function ensureReturnRecord(User $actor, Order $order, string $payment = 'reconciliation'): ReturnRecord
    {
        if ($record = $order->returnRecord()->first()) {
            return $record;
        }
        $this->ledger->ensure(isset(ReturnRecord::PAYMENTS[$payment]), 'Cách thu phí hoàn không hợp lệ.');
        $snapshot = $order->fee_snapshot ?? [];
        $legacy = ! array_key_exists('return_fee', $snapshot);
        $fee = $legacy ? 0 : (int) $snapshot['return_fee'];
        $record = ReturnRecord::create(['order_id' => $order->id, 'warehouse_id' => $order->warehouse_id, 'authorized_by' => $order->return_authorized_by,
            'status' => 'authorized', 'fee_payment' => $payment, 'fee_amount' => $fee, 'reason' => $order->return_reason,
            'fee_snapshot' => ['version_id' => $order->fee_version_id, 'rule_id' => $order->fee_rule_id, 'return_fee' => $fee, 'shipping_fee' => $order->shipping_fee, 'source' => $legacy ? 'legacy_waived' : 'order_snapshot']]);
        $this->audit->record($actor, $record, 'return.recorded', [], ['fee_amount' => $fee, 'fee_payment' => $payment, 'status' => 'authorized'], $order->return_reason);

        return $record;
    }
}
