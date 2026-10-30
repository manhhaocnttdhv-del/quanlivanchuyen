<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\DeliveryCollection;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Support\FinanceRules;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class CashDepositService
{
    public function __construct(private FinancePosting $posting, private FinanceLedger $ledger, private FinancePolicy $policy, private FinanceEvidence $evidence) {}

    public function create(User $actor, array $input): CashDeposit
    {
        $data = Validator::make($input, FinanceRules::deposit())->validate();
        $data['warehouse_id'] = (int) $data['warehouse_id'];
        $data['shipper_id'] = (int) $data['shipper_id'];
        $data['allocations'] = FinanceRules::amounts($data['allocations']);
        abort_unless($this->policy->deposit($actor, $data['warehouse_id'], $data['shipper_id']), 403);

        return $this->posting->run(function () use ($actor, $data) {
            $hash = $this->posting->hash($data);
            if ($existing = $this->posting->replay(CashDeposit::class, $actor, $data, $hash)) {
                return $existing;
            }
            $shipper = User::findOrFail($data['shipper_id']);
            $this->posting->ensure($shipper->hasRole('shipper') && $shipper->status === 'active' && $shipper->warehouses()->whereKey($data['warehouse_id'])->exists(), 'Shipper phải hoạt động và thuộc đúng kho.', 'shipper_id');
            $sources = $this->collections($data['allocations'], $data['warehouse_id'], $shipper->id);
            $total = Money::bounded(array_sum($data['allocations']));
            $this->posting->ensure($total > 0, 'Chọn khoản thu và số tiền đề nghị nộp.', 'allocations');
            foreach ($sources as $source) {
                $this->posting->ensure($data['allocations'][$source->id] <= $this->ledger->held($source), 'Tiền đề nghị vượt số shipper còn giữ của khoản thu #'.$source->id, 'allocations');
            }
            $deposit = CashDeposit::create($this->posting->identity('NT', $actor, $data, $hash) + ['warehouse_id' => $data['warehouse_id'], 'shipper_id' => $shipper->id, 'status' => 'submitted', 'requested_amount' => $total]);
            foreach ($data['allocations'] as $id => $amount) {
                $deposit->lines()->create(['delivery_collection_id' => $id, 'amount' => $amount]);
            }
            $this->posting->event($actor, $deposit, 'deposit_submitted', $data['reason']);

            return $deposit;
        });
    }

    public function confirm(User $actor, CashDeposit $deposit, array $input, ?UploadedFile $file): void
    {
        $data = Validator::make($input, FinanceRules::confirmation())->validate();
        $data['received_amount'] = Money::value($data['received_amount']);
        $data['allocations'] = FinanceRules::amounts($data['allocations']);
        abort_unless($this->policy->approve($actor, $deposit), 403);
        $this->evidence->run($file, true, fn ($proof) => $this->posting->run(function () use ($actor, $deposit, $data, $proof) {
            $deposit = CashDeposit::lockForUpdate()->findOrFail($deposit->id);
            abort_unless($this->policy->approve($actor, $deposit), 403);
            $hash = $this->posting->hash($data + ['actor' => $actor->id], $proof);
            if ($deposit->status === 'confirmed') {
                $this->posting->ensure($deposit->events()->where('event', 'deposit_confirmed')->where('payload->confirmation_hash', $hash)->exists(), 'Phiếu đã xác nhận với nội dung khác.');

                return;
            }
            $this->posting->revision($deposit, $data);
            $this->posting->ensure($deposit->status === 'submitted', 'Phiếu không còn chờ xác nhận.');
            $this->posting->referenceAvailable($data['transaction_reference']);
            $requestedIds = $deposit->lines()->pluck('delivery_collection_id')->all();
            $this->posting->ensure(! array_diff(array_keys($data['allocations']), $requestedIds), 'Xác nhận chỉ phân bổ các khoản thu trong đề nghị.', 'allocations');
            $deposit->received_amount = $data['received_amount'];
            $deposit->confirmed_by = $actor->id;
            $deposit->transaction_reference = $data['transaction_reference'];
            $deposit->received_at = now();
            $deposit->status = 'confirmed';
            $deposit->revision++;
            $deposit->save();
            $this->allocate($actor, $deposit, $data['allocations']);
            $this->evidence->attach($actor, $deposit, $proof);
            $this->posting->event($actor, $deposit, 'deposit_confirmed', $data['reason'], ['confirmation_hash' => $hash, 'difference' => $data['received_amount'] - Money::value($deposit->requested_amount), 'unallocated_amount' => $this->ledger->unallocated($deposit)]);
        }));
    }

    public function allocateRemaining(User $actor, CashDeposit $deposit, array $input): void
    {
        $data = Validator::make($input, FinanceRules::action() + FinanceRules::allocations('allocations'))->validate();
        $amounts = FinanceRules::amounts($data['allocations']);
        $this->posting->run(function () use ($actor, $deposit, $data, $amounts) {
            $deposit = CashDeposit::lockForUpdate()->findOrFail($deposit->id);
            abort_unless($this->policy->staff($actor, $deposit->warehouse_id) && $actor->id !== $deposit->shipper_id && $actor->id !== $deposit->created_by, 403);
            $this->posting->revision($deposit, $data);
            $this->posting->ensure($deposit->status === 'confirmed' && array_sum($amounts) > 0, 'Phiếu chưa nhận tiền hoặc chưa chọn phân bổ.');
            $this->allocate($actor, $deposit, $amounts);
            $deposit->increment('revision');
            $this->posting->event($actor, $deposit, 'deposit_allocated', $data['reason'], ['allocated_amount' => array_sum($amounts), 'unallocated_amount' => $this->ledger->unallocated($deposit)]);
        });
    }

    public function reject(User $actor, CashDeposit $deposit, array $input): void
    {
        $data = Validator::make($input, FinanceRules::action())->validate();
        $this->posting->run(function () use ($actor, $deposit, $data) {
            $deposit = CashDeposit::lockForUpdate()->findOrFail($deposit->id);
            abort_unless($this->policy->approve($actor, $deposit), 403);
            $this->posting->revision($deposit, $data);
            $this->posting->ensure($deposit->status === 'submitted', 'Phiếu không còn chờ xác nhận.');
            $deposit->update(['status' => 'rejected', 'revision' => $deposit->revision + 1]);
            $this->posting->event($actor, $deposit, 'deposit_rejected', $data['reason']);
        });
    }

    private function collections(array $amounts, int $warehouse, int $shipper)
    {
        $sources = DeliveryCollection::whereIn('id', array_keys($amounts))->orderBy('id')->lockForUpdate()->get();
        $this->posting->ensure($sources->count() === count($amounts) && $sources->every(fn ($c) => $c->shipper_id === $shipper && $c->warehouse_id === $warehouse), 'Khoản thu phải thuộc đúng shipper và kho.', 'allocations');

        return $sources;
    }

    private function allocate(User $actor, CashDeposit $deposit, array $amounts): void
    {
        $this->posting->ensure(array_sum($amounts) <= $this->ledger->unallocated($deposit), 'Tổng phân bổ vượt tiền thực nhận chưa phân bổ.', 'allocations');
        foreach ($this->collections($amounts, $deposit->warehouse_id, $deposit->shipper_id) as $source) {
            $this->posting->ensure($amounts[$source->id] <= $this->ledger->held($source), 'Phân bổ vượt số khoản thu còn phải nộp #'.$source->id, 'allocations');
            $deposit->allocations()->create(['delivery_collection_id' => $source->id, 'amount' => $amounts[$source->id], 'created_by' => $actor->id]);
        }
    }
}
