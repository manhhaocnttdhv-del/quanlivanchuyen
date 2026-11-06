<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Order;
use App\Models\User;
use App\Policies\IncidentPolicy;
use App\Support\CaseRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class IncidentService
{
    public function __construct(private LogisticsLedger $ledger, private IncidentPolicy $policy, private AuditService $audit, private CaseEvidence $evidence) {}

    public function create(User $actor, Order $order, array $input, ?UploadedFile $file = null): Incident
    {
        abort_unless($this->policy->createForOrder($actor, $order), 403);
        $data = Validator::make($input, CaseRules::incident())->validate();
        Validator::make(['evidence' => $file], ['evidence' => CaseRules::evidence(in_array($data['type'], ['lost', 'damaged', 'money']))])->validate();
        $data['blocks_dispatch'] = (bool) $data['blocks_dispatch'] || $data['type'] === 'lost';
        $data['blocks_reconciliation'] = (bool) $data['blocks_reconciliation'] || $data['type'] === 'money';
        $hash = hash('sha256', json_encode(['order' => $order->id, 'data' => $data, 'file' => $file ? hash_file('sha256', $file->getRealPath()) : null], JSON_THROW_ON_ERROR));

        return $this->evidence->run($file, fn ($proof) => DB::transaction(function () use ($actor, $order, $data, $hash, $proof) {
            $order = $this->ledger->lock($order);
            abort_unless($this->policy->createForOrder($actor, $order), 403);
            User::lockForUpdate()->findOrFail($actor->id);
            $existing = Incident::where('created_by', $actor->id)->where('client_token', $data['client_token'])->lockForUpdate()->first();
            if ($existing) {
                $this->ledger->ensure(hash_equals($existing->creation_hash, $hash), 'Mã yêu cầu đã dùng cho nội dung khác.', 'client_token');

                return $existing;
            }
            $this->checkReferences($order, $data);
            $before = $this->ledger->snapshot($order);
            $incident = Incident::create($data + ['code' => 'SC'.Str::upper((string) Str::ulid()), 'order_id' => $order->id, 'warehouse_id' => $order->warehouse_id, 'created_by' => $actor->id, 'creation_hash' => $hash]);
            $action = $incident->actions()->create(['created_by' => $actor->id, 'type' => 'created', 'reason' => $data['description'], 'payload' => $incident->only(['blocks_dispatch', 'blocks_reconciliation'])]);
            $this->evidence->attach($actor, $action, $proof);
            $this->ledger->record($actor, $order, 'incident_opened', $before, $incident->code.': mở sự cố và ghi nhận phạm vi khóa.');
            $this->audit->record($actor, $incident, 'incident.created', [], $incident->toArray(), $data['description']);

            return $incident;
        }, 3));
    }

    public function act(User $actor, Incident $incident, string $operation, array $input, ?UploadedFile $file = null): void
    {
        $this->ledger->ensure(in_array($operation, ['note', 'assign', 'hold', 'propose', 'review']), 'Thao tác không hợp lệ.');
        abort_unless($operation === 'review' ? $this->policy->approve($actor, $incident) : ($operation === 'note' ? $this->policy->view($actor, $incident) : $this->policy->manage($actor, $incident)), 403);
        $data = Validator::make($input, CaseRules::incidentAction($operation))->validate();
        $reference = $incident->order;
        $this->evidence->run($file, fn ($proof) => DB::transaction(function () use ($actor, $incident, $operation, $data, $reference, $proof) {
            $order = $this->ledger->lock($reference);
            $incident = Incident::lockForUpdate()->findOrFail($incident->id);
            abort_unless($operation === 'review' ? $this->policy->approve($actor, $incident) : ($operation === 'note' ? $this->policy->view($actor, $incident) : $this->policy->manage($actor, $incident)), 403);
            $this->ledger->ensure($incident->revision === (int) $data['revision'], 'Hồ sơ đã thay đổi. Tải lại trang.', 'revision');
            $this->ledger->ensure($incident->status !== 'closed', 'Hồ sơ đã kết luận, không được thay đổi.');
            $before = $incident->toArray();
            $orderBefore = $this->ledger->snapshot($order);
            $type = match ($operation) {
                'assign' => 'assigned', 'hold' => 'hold_added', 'propose' => 'proposed', 'review' => $data['decision'], default => 'note'
            };
            $payload = [];
            $proposedAmount = null;
            if ($operation === 'assign') {
                $target = User::findOrFail($data['assigned_to']);
                $this->ledger->ensure($this->policy->manage($target, $incident), 'Người xử lý phải hoạt động, có quyền và thuộc đúng kho.', 'assigned_to');
                $incident->assigned_to = $target->id;
                $payload = ['assigned_to' => $target->id];
            } elseif ($operation === 'hold') {
                $this->ledger->ensure($incident->status === 'open', 'Không đổi khóa khi chờ duyệt.');
                $this->ledger->ensure($data['blocks_dispatch'] || $data['blocks_reconciliation'], 'Chọn ít nhất một phạm vi khóa.');
                // Chỉ bổ sung khóa; gỡ khóa phải đi qua kết luận được người khác duyệt.
                $incident->blocks_dispatch = $incident->blocks_dispatch || (bool) $data['blocks_dispatch'];
                $incident->blocks_reconciliation = $incident->blocks_reconciliation || (bool) $data['blocks_reconciliation'];
                $payload = $incident->only(['blocks_dispatch', 'blocks_reconciliation']);
            } elseif ($operation === 'propose') {
                $this->ledger->ensure($incident->status === 'open', 'Đã có kết luận đang chờ duyệt.');
                $this->ledger->ensure($incident->type !== 'lost' || in_array($data['outcome'], ['compensation', 'no_financial']), 'Mất hàng phải xác nhận bồi thường hoặc tìm đủ kiện về kho.', 'outcome');
                $financial = $data['outcome'] !== 'no_financial';
                $this->ledger->ensure(! $financial || $proof || $incident->actions()->whereHas('attachments')->exists(), 'Đề xuất tài chính phải có bằng chứng.', 'evidence');
                $this->ledger->ensure($financial ? (int) $data['amount'] > 0 : (int) $data['amount'] === 0, 'Kết luận tài chính cần số tiền dương; không điều chỉnh thì nhập 0.', 'amount');
                $incident->status = 'in_review';
                if ($financial) {
                    $incident->blocks_reconciliation = true;
                }
                $payload = Arr::only($data, ['outcome', 'conclusion']);
                $proposedAmount = (string) $data['amount'];
            } elseif ($operation === 'review') {
                $this->ledger->ensure($incident->status === 'in_review' && $incident->proposal_action_id !== null, 'Chưa có đề xuất để duyệt.');
                $proposal = $incident->proposal()->lockForUpdate()->firstOrFail();
                $this->ledger->ensure($actor->id !== $incident->created_by && $actor->id !== $proposal->created_by, 'Người lập sự cố hoặc đề xuất không được tự duyệt.');
                $payload = ['proposal_action_id' => $proposal->id] + $proposal->payload;
                $proposedAmount = $proposal->proposed_amount;
                if ($data['decision'] === 'approved') {
                    $confirmedLoss = $incident->type === 'lost' && $proposal->payload['outcome'] === 'compensation';
                    if ($incident->type === 'lost') {
                        $loss = app(ParcelLossService::class);
                        $confirmedLoss ? $loss->confirm($actor, $order, $incident) : $loss->ensureRecovered($order, $incident);
                    }
                    $incident->status = 'closed';
                    $incident->closed_at = now();
                    $incident->financial_status = $proposal->payload['outcome'] === 'no_financial' ? 'none' : 'pending_finance';
                    $incident->blocks_dispatch = $confirmedLoss;
                    $incident->blocks_reconciliation = $incident->financial_status === 'pending_finance';
                } else {
                    $incident->status = 'open';
                    $incident->proposal_action_id = null;
                }
            }
            $action = $incident->actions()->create(['created_by' => $actor->id, 'type' => $type, 'reason' => $data['reason'], 'payload' => $payload, 'proposed_amount' => $proposedAmount]);
            $this->evidence->attach($actor, $action, $proof);
            if ($operation === 'propose') {
                $incident->proposal_action_id = $action->id;
            }
            if ($operation === 'review' && $data['decision'] === 'approved') {
                $incident->approved_action_id = $action->id;
            }
            $incident->revision++;
            $incident->save();
            $this->ledger->record($actor, $order, 'incident_updated', $orderBefore, $incident->code.': '.IncidentAction::TYPES[$type].'.');
            $this->audit->record($actor, $incident, 'incident.'.$type, $before, $incident->toArray() + $payload + ['proposed_amount' => $action->proposed_amount], $data['reason']);
        }, 3));
    }

    private function checkReferences(Order $order, array $data): void
    {
        foreach (['warehouse_item_id' => 'parcels', 'delivery_task_id' => 'tasks', 'delivery_collection_id' => 'collections', 'discrepancy_id' => 'discrepancies'] as $field => $relation) {
            if (! empty($data[$field])) {
                $this->ledger->ensure($order->$relation()->whereKey($data[$field])->exists(), 'Chứng từ/kiện phải thuộc đúng vận đơn.', $field);
            }
        }
    }
}
