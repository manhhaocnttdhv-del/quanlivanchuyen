<?php

namespace App\Services;

use App\Models\DeliveryAttempt;
use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\User;
use App\Policies\LogisticsPolicy;
use App\Support\DeliveryRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class DeliveryService
{
    public function __construct(private LogisticsLedger $ledger, private LogisticsPolicy $policy, private AuditService $audit) {}

    public function record(User $actor, DeliveryTask $task, array $input, ?UploadedFile $photo): DeliveryAttempt
    {
        $reference = $task->order;
        $data = Validator::make($input, DeliveryRules::attempt($task->type))->validate();
        $needsPhoto = $data['outcome'] === 'success' || in_array($data['reason_code'] ?? null, ['damaged', 'lost']);
        Validator::make(['evidence' => $photo], ['evidence' => [$needsPhoto ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']])->validate();
        $photoHash = $photo ? hash_file('sha256', $photo->getRealPath()) : null;
        $hash = $this->requestHash($task, $data, $photoHash);
        $path = null;
        try {
            $attempt = DB::transaction(function () use ($actor, $task, $data, $reference, $photo, $photoHash, $hash, &$path) {
                $order = $this->ledger->lock($reference);
                $task = DeliveryTask::lockForUpdate()->findOrFail($task->id);
                abort_unless($this->policy->shipper($actor, $task), 403);
                // Cùng shipper có thể thao tác hai đơn: khóa này bảo vệ token xuyên vận đơn.
                User::lockForUpdate()->findOrFail($actor->id);
                $existing = DeliveryAttempt::where('shipper_id', $actor->id)->where('client_token', $data['client_token'])->lockForUpdate()->first();
                if ($existing) {
                    $this->ledger->ensure($existing->delivery_task_id === $task->id && hash_equals($existing->request_hash, $hash), 'Mã xác nhận đã dùng với nội dung khác. Mở lần giao đã lưu.', 'client_token');

                    return $existing->load('proof');
                }
                $this->ledger->ensure(in_array($task->type, ['delivery', 'return']) && $task->status === 'in_custody', 'Chỉ xác nhận nhiệm vụ giao/hoàn đang giữ đủ kiện.');
                $this->ledger->ensure(! $task->deliveryAttempt()->exists(), 'Nhiệm vụ đã có kết quả; mở lần giao đã lưu.');
                $this->ledger->ensure($order->status === ($task->type === 'return' ? 'returning' : 'out_for_delivery'), 'Trạng thái vận đơn không phù hợp.');
                $this->ledger->ensure(! $task->handovers()->where('status', 'pending')->exists() && ! $order->pendingChange()->exists(), 'Đang có bàn giao hoặc đề nghị thay đổi chờ xử lý.');
                $items = $order->parcels()->lockForUpdate()->get();
                $this->ledger->ensure($items->count() === $order->parcel_count && $items->every(fn ($item) => $item->holder_type === 'shipper' && $item->holder_user_id === $actor->id), 'Shipper phải đang giữ toàn bộ kiện của vận đơn.');
                $success = $data['outcome'] === 'success';
                if ($success) {
                    app(OrderRouting::class)->ensureReady($order, $task->type);
                    $this->ledger->ensureNoIncidentDispatchHold($order);
                    $this->ledger->ensure(! $order->discrepancies()->where('status', 'open')->where('blocks_dispatch', true)->exists(), 'Đơn đang giữ vì sai lệch, chưa được giao/hoàn thành công.');
                }
                $return = $task->type === 'return' ? $order->returnRecord()->lockForUpdate()->first() : null;
                $this->ledger->ensure($task->type !== 'return' || ($return && $return->status !== 'returned'), 'Thiếu hồ sơ hoàn hoặc đã hoàn xong.');
                $this->ledger->ensure($task->type !== 'return' || $order->return_attempt_count < 100, 'Đã đạt 100 lượt hoàn, cần xử lý riêng với shop.');
                $this->ledger->ensure($task->type !== 'delivery' || $order->delivery_attempt_count < $order->delivery_attempt_limit, 'Đã chạm giới hạn giao; cần điều phối cho phép thêm.');
                $expectedCod = $task->type === 'delivery' ? (int) $order->cod_amount : 0;
                $expectedFee = $return ? ($return->fee_payment === 'collect_on_return' ? (int) $return->fee_amount : 0) : ($order->fee_payer === 'recipient' ? (int) $order->shipping_fee : 0);
                $actualCod = $success ? (int) ($data['actual_cod'] ?? 0) : 0;
                $actualFee = $success ? (int) $data['actual_fee'] : 0;
                $mismatch = $success && ($actualCod !== $expectedCod || $actualFee !== $expectedFee);
                $this->ledger->ensure(! $mismatch || mb_strlen(trim($data['financial_reason'] ?? '')) >= 5, 'Thực thu khác dự kiến; cần lý do để đưa vào danh sách chênh lệch tài chính.', 'financial_reason');
                $before = $this->ledger->snapshot($order);
                if ($photo && ! $path) {
                    $path = $photo->store('delivery-evidence', 'local');
                    $this->ledger->ensure(is_string($path) && $path !== '', 'Không lưu được ảnh bằng chứng. Thử lại.', 'evidence');
                }
                $counter = $return ? 'return_attempt_count' : 'delivery_attempt_count';
                $order->$counter++;
                $attempt = DeliveryAttempt::create(['order_id' => $order->id, 'delivery_task_id' => $task->id, 'shipper_id' => $actor->id, 'return_record_id' => $return?->id,
                    'type' => $task->type, 'attempt_number' => $order->$counter, 'outcome' => $data['outcome'], 'reason_code' => $success ? null : $data['reason_code'],
                    'reason' => $data['reason'], 'expected_cod' => $expectedCod, 'expected_fee' => $expectedFee, 'actual_cod' => $actualCod, 'actual_fee' => $actualFee,
                    'financial_status' => $mismatch ? 'disputed' : 'matched', 'financial_reason' => $mismatch ? $data['financial_reason'] : null,
                    'client_token' => $data['client_token'], 'request_hash' => $hash, 'attempted_at' => now()]);
                $attempt->proof()->create(['receiver_name' => $success ? $data['receiver_name'] : null, 'evidence_path' => $path, 'content_sha256' => $photoHash]);
                $order->retry_authorized_attempt = null;
                if ($success) {
                    foreach ($items as $item) {
                        $item->update(['holder_type' => $return ? 'shop' : 'recipient', 'holder_user_id' => null, 'warehouse_location_id' => null, 'custody_version' => $item->custody_version + 1]);
                    }
                    $order->status = $return ? 'returned' : 'delivered';
                    $order->{$return ? 'returned_at' : 'delivered_at'} = now();
                    $order->financial_hold = $order->financial_hold || $mismatch;
                    $task->status = 'completed';
                    $task->finished_at = now();
                    $order->assigned_shipper_id = null;
                    if ($return) {
                        $return->update(['status' => 'returned', 'returned_at' => now(), 'fee_charged_at' => now()]);
                        $this->collect($actor, $attempt, $order->warehouse_id, 'return_fee', $expectedFee, $actualFee);
                    } else {
                        $this->collect($actor, $attempt, $order->warehouse_id, 'cod', $expectedCod, $actualCod);
                        $this->collect($actor, $attempt, $order->warehouse_id, 'shipping_fee', $expectedFee, $actualFee);
                    }
                } else {
                    $task->status = 'recall_pending';
                    $order->status = $return ? 'return_failed' : 'delivery_failed';
                    if ($return) {
                        $return->update(['status' => 'failed']);
                    }
                    if (in_array($data['reason_code'], ['damaged', 'lost'])) {
                        Discrepancy::create(['warehouse_id' => $order->warehouse_id, 'order_id' => $order->id, 'warehouse_item_id' => $items->first()->id,
                            'created_by' => $actor->id, 'type' => $data['reason_code'] === 'lost' ? 'missing' : 'damaged', 'severity' => 'high', 'reason' => $data['reason'], 'evidence_path' => $path]);
                    }
                }
                $task->save();
                app(ReceivableService::class)->accrue($order, $actor);
                $event = ($return ? 'return_' : 'delivery_').($success ? 'succeeded' : 'failed');
                $this->ledger->record($actor, $order, $event, $before, $data['reason']);
                $this->audit->record($actor, $attempt, 'delivery.attempt_recorded', [], $attempt->toArray(), $data['reason']);

                return $attempt->load('proof');
            }, 3);
            if ($path && $attempt->proof->getRawOriginal('evidence_path') !== $path) {
                Storage::disk('local')->delete($path);
            }

            return $attempt;
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function collect(User $actor, DeliveryAttempt $attempt, int $warehouseId, string $type, int $expected, int $amount): void
    {
        $collection = $attempt->collections()->create(['order_id' => $attempt->order_id, 'warehouse_id' => $warehouseId, 'shipper_id' => $actor->id, 'type' => $type,
            'amount' => $amount, 'expected_amount' => $expected, 'financial_status' => $amount === $expected ? 'matched' : 'disputed', 'collected_at' => $attempt->attempted_at,
            'idempotency_key' => hash('sha256', $attempt->id.':'.$type)]);
        $this->audit->record($actor, $collection, 'delivery.collection_recorded', [], $collection->toArray(), $attempt->financial_reason ?? $attempt->reason);
    }

    private function requestHash(DeliveryTask $task, array $data, ?string $photoHash): string
    {
        $canonical = ['task' => $task->id, 'outcome' => $data['outcome'], 'reason_code' => $data['reason_code'] ?? null, 'reason' => $data['reason'],
            'receiver_name' => $data['receiver_name'] ?? null, 'actual_cod' => (int) ($data['actual_cod'] ?? 0), 'actual_fee' => (int) ($data['actual_fee'] ?? 0),
            'financial_reason' => $data['financial_reason'] ?? null, 'photo' => $photoHash];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
