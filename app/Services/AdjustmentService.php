<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\DeliveryCollection;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Support\FinanceRules;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class AdjustmentService
{
    public function __construct(private FinancePosting $posting, private FinanceLedger $ledger, private FinancePolicy $policy, private FinanceEvidence $evidence, private AuditService $audit) {}

    public function create(User $actor, Order $order, array $input, ?UploadedFile $file): Adjustment
    {
        $data = Validator::make($input, FinanceRules::adjustment())->validate();
        $data['amount'] = Money::value($data['amount']);
        foreach (['incident_id', 'reverses_adjustment_id', 'delivery_collection_id', 'receivable_id'] as $field) {
            $data[$field] = empty($data[$field]) ? null : (int) $data[$field];
        }
        abort_unless($this->policy->staff($actor, $order->warehouse_id), 403);

        return $this->evidence->run($file, true, fn ($proof) => $this->posting->run(function () use ($actor, $order, $data, $proof) {
            $order = $this->posting->orders([$order->id])[$order->id];
            abort_unless($this->policy->staff($actor, $order->warehouse_id), 403);
            $hash = $this->posting->hash($data + ['order_id' => $order->id], $proof);
            if ($existing = $this->posting->replay(Adjustment::class, $actor, $data, $hash)) {
                return $existing;
            }
            $this->posting->ensure(($data['kind'] === 'reversal') === ($data['reverses_adjustment_id'] !== null) && (($data['incident_id'] !== null) !== ($data['reverses_adjustment_id'] !== null)), 'Chọn đúng một căn cứ: sự cố hoặc điều chỉnh gốc.');
            $line = $data['kind'] === 'reversal' ? $this->reversalLine($order, $data) : $this->incidentLine($order, $data);
            $document = Adjustment::create($this->posting->identity('DC', $actor, $data, $hash) + ['order_id' => $order->id, 'warehouse_id' => $order->warehouse_id, 'incident_id' => $data['incident_id'],
                'reverses_adjustment_id' => $data['reverses_adjustment_id'], 'kind' => $data['kind'], 'amount' => $data['amount'], 'status' => 'submitted']);
            $document->line()->create($line);
            $this->evidence->attach($actor, $document, $proof);
            $this->posting->event($actor, $document, 'adjustment_submitted', $data['reason']);

            return $document;
        }));
    }

    public function review(User $actor, Adjustment $document, string $decision, array $input): void
    {
        $data = Validator::make($input, FinanceRules::action())->validate();
        $this->posting->run(function () use ($actor, $document, $decision, $data) {
            $order = $this->posting->orders([$document->order_id])[$document->order_id];
            $document = Adjustment::lockForUpdate()->findOrFail($document->id);
            abort_unless($this->policy->approve($actor, $document), 403);
            $this->posting->revision($document, $data);
            $this->posting->ensure($document->status === 'submitted' && in_array($decision, ['approve', 'reject']), 'Chứng từ không còn chờ duyệt.');
            if ($decision === 'approve') {
                $line = $document->line;
                if ($document->kind === 'reversal') {
                    $current = $this->reversalLine($order, ['reverses_adjustment_id' => $document->reverses_adjustment_id, 'amount' => Money::value($document->amount)], $document->id);
                } else {
                    $current = $this->incidentLine($order, ['incident_id' => $document->incident_id, 'kind' => $document->kind, 'amount' => Money::value($document->amount), 'delivery_collection_id' => $line->delivery_collection_id, 'receivable_id' => $line->receivable_id], $document->id);
                }
                $this->posting->ensure($current === $this->lineValues($line), 'Nguồn đã thay đổi từ lúc đề xuất. Từ chối và lập lại chứng từ.');
                $this->checkEffect($line);
                $document->status = 'approved';
                $document->approved_by = $actor->id;
                $document->approved_at = now();
            } else {
                $document->status = 'rejected';
            }
            $document->revision++;
            $document->save();
            if ($decision === 'approve') {
                $this->settleIncident($actor, $document, $order, $data['reason']);
            }
            $this->posting->event($actor, $document, 'adjustment_'.$decision, $data['reason']);
        });
    }

    private function incidentLine(Order $order, array $data, ?int $except = null): array
    {
        $incident = Incident::lockForUpdate()->findOrFail($data['incident_id']);
        $this->posting->ensure($incident->order_id === $order->id && $incident->status === 'closed' && $incident->financial_status === 'pending_finance' && $incident->approved_action_id, 'Cần sự cố cùng đơn đã duyệt và chờ tài chính.', 'incident_id');
        $this->posting->ensure(! Adjustment::where('incident_id', $incident->id)->whereIn('status', ['submitted', 'approved'])->when($except, fn ($q) => $q->whereKeyNot($except))->whereDoesntHave('reversal')->exists(), 'Sự cố đã có điều chỉnh hiệu lực.', 'incident_id');
        $approval = $incident->approvedAction;
        $outcome = match ($data['kind']) {
            'compensation' => 'compensation', 'fee_reduction' => 'fee_reduction', default => 'financial_correction'
        };
        $this->posting->ensure($approval->payload['outcome'] === $outcome && Money::value($approval->proposed_amount) === $data['amount'], 'Loại và số tiền phải khớp kết luận sự cố đã duyệt.', 'amount');
        $line = ['order_id' => null, 'delivery_collection_id' => null, 'receivable_id' => null, 'amount' => $data['amount'], 'verified_amount' => null];
        if ($data['kind'] === 'compensation') {
            $this->posting->ensure(empty($data['delivery_collection_id']) && empty($data['receivable_id']), 'Bồi thường tham chiếu vận đơn, không gán khoản thu/phí.');
            $line['order_id'] = $order->id;
        } elseif ($data['kind'] === 'fee_reduction') {
            $fee = Receivable::lockForUpdate()->findOrFail($data['receivable_id']);
            $this->posting->ensure($fee->order_id === $order->id && empty($data['delivery_collection_id']), 'Chỉ giảm phí của chính đơn này.', 'receivable_id');
            $this->posting->ensure($data['amount'] <= $this->ledger->feeAvailable($fee), 'Giảm phí vượt phần chưa thanh toán/đối soát.', 'amount');
            $line['receivable_id'] = $fee->id;
            $line['amount'] = -$data['amount'];
        } else {
            $collection = DeliveryCollection::lockForUpdate()->findOrFail($data['delivery_collection_id']);
            $this->posting->ensure($collection->order_id === $order->id && empty($data['receivable_id']) && (! $incident->delivery_collection_id || $incident->delivery_collection_id === $collection->id), 'Khoản thu phải khớp đơn và căn cứ sự cố.', 'delivery_collection_id');
            $line['delivery_collection_id'] = $collection->id;
            if ($data['kind'] === 'collection_verification') {
                $this->posting->ensure(abs($this->ledger->effective($collection) - Money::value($collection->expected_amount)) === $data['amount'], 'Số xác minh phải bằng chênh lệch thực thu/dự kiến hiện tại.', 'amount');
                $line['amount'] = 0;
            } else {
                $line['amount'] = $data['kind'] === 'collection_decrease' ? -$data['amount'] : $data['amount'];
            }
            $line['verified_amount'] = Money::bounded($this->ledger->effective($collection) + $line['amount']);
        }

        return $line;
    }

    private function reversalLine(Order $order, array $data, ?int $except = null): array
    {
        $original = Adjustment::lockForUpdate()->findOrFail($data['reverses_adjustment_id']);
        $this->posting->ensure($original->order_id === $order->id && $original->status === 'approved' && $original->kind !== 'reversal' && Money::value($original->amount) === $data['amount'], 'Chỉ đảo toàn bộ điều chỉnh gốc đã duyệt cùng đơn.', 'reverses_adjustment_id');
        $this->posting->ensure(! Adjustment::where('reverses_adjustment_id', $original->id)->whereIn('status', ['submitted', 'approved'])->when($except, fn ($q) => $q->whereKeyNot($except))->exists(), 'Đã có chứng từ đảo hiệu lực.');
        $line = $this->lineValues($original->line);
        $line['amount'] = -$line['amount'];
        if ($original->kind === 'compensation') {
            $this->posting->ensure($this->ledger->reserved('adjustment_id', $original->id) === 0, 'Bồi thường đã vào đối soát; không được đảo căn cứ đã sử dụng.');
        }
        if ($line['receivable_id']) {
            $fee = Receivable::findOrFail($line['receivable_id']);
            $this->posting->ensure($this->ledger->reserved('receivable_id', $fee->id) === 0 && ! $fee->receiptAllocations()->exists(), 'Phí đã được sử dụng; không đảo hồi tố.');
        }
        if ($line['delivery_collection_id']) {
            $line['verified_amount'] = $this->ledger->effective(DeliveryCollection::findOrFail($line['delivery_collection_id'])) + $line['amount'];
        }

        return $line;
    }

    private function lineValues($line): array
    {
        return ['order_id' => $line->order_id, 'delivery_collection_id' => $line->delivery_collection_id, 'receivable_id' => $line->receivable_id, 'amount' => Money::value($line->amount), 'verified_amount' => $line->verified_amount === null ? null : Money::value($line->verified_amount)];
    }

    private function checkEffect($line): void
    {
        if ($line->delivery_collection_id) {
            $collection = DeliveryCollection::lockForUpdate()->findOrFail($line->delivery_collection_id);
            $new = Money::bounded($this->ledger->effective($collection) + Money::value($line->amount));
            $this->posting->ensure($new >= max($this->ledger->deposited($collection->id), $this->ledger->reserved('delivery_collection_id', $collection->id)), 'Điều chỉnh làm thấp hơn tiền đã nộp/đối soát; không sửa hồi tố.');
        }
    }

    private function settleIncident(User $actor, Adjustment $document, Order $order, string $reason): void
    {
        $incident = $document->kind === 'reversal' ? $document->original->incident : $document->incident;
        $before = $incident->toArray();
        $reversed = $document->kind === 'reversal';
        $incident->financial_status = $reversed ? 'pending_finance' : 'processed';
        $incident->blocks_reconciliation = $reversed;
        $incident->revision++;
        $incident->save();
        $incident->actions()->create(['created_by' => $actor->id, 'type' => $reversed ? 'finance_reversed' : 'finance_posted', 'reason' => $reason, 'payload' => ['adjustment_id' => $document->id, 'code' => $document->code]]);
        $this->audit->record($actor, $incident, 'incident.finance_processed', $before, $incident->toArray(), $reason);
        $unresolved = false;
        foreach ($order->collections()->get() as $collection) {
            if (Money::value($collection->amount) === Money::value($collection->expected_amount)) {
                continue;
            }
            $effective = $this->ledger->effective($collection);
            $verified = $collection->adjustmentLines()->where('verified_amount', $effective)->whereHas('adjustment', fn ($q) => $q->where('status', 'approved')->where('kind', '!=', 'reversal')->whereDoesntHave('reversal'))->exists();
            if (! $verified) {
                $unresolved = true;
            }
        }
        if ($order->financial_hold !== $unresolved) {
            $old = ['financial_hold' => $order->financial_hold];
            $order->update(['financial_hold' => $unresolved]);
            $this->audit->record($actor, $order, 'finance.collection_hold_reviewed', $old, ['financial_hold' => $unresolved], $reason);
        }
    }
}
