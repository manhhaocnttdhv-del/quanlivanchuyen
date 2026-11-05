<?php

namespace App\Services;

use App\Models\FeeReceipt;
use App\Models\Receivable;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Support\FinanceRules;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class FeeReceiptService
{
    public function __construct(private FinancePosting $posting, private FinanceLedger $ledger, private FinancePolicy $policy, private FinanceEvidence $evidence) {}

    public function record(User $actor, array $input, ?UploadedFile $file): FeeReceipt
    {
        $data = Validator::make($input, FinanceRules::receipt())->validate();
        $data['amount'] = Money::value($data['amount']);
        $data['warehouse_id'] = (int) $data['warehouse_id'];
        $data['shop_id'] = (int) $data['shop_id'];
        $data['allocations'] = FinanceRules::amounts($data['allocations']);
        abort_unless($this->policy->staff($actor, $data['warehouse_id']), 403);

        return $this->evidence->run($file, true, fn ($proof) => $this->posting->run(function () use ($actor, $data, $proof) {
            $hash = $this->posting->hash($data, $proof);
            if ($existing = $this->posting->replay(FeeReceipt::class, $actor, $data, $hash)) {
                return $existing;
            }
            $this->posting->ensure(array_sum($data['allocations']) === $data['amount'], 'Tổng phân bổ phí phải bằng tiền thực nhận.', 'allocations');
            $this->posting->referenceAvailable($data['transaction_reference']);
            $fees = Receivable::whereIn('id', array_keys($data['allocations']))->orderBy('id')->lockForUpdate()->get();
            $this->posting->ensure($fees->count() === count($data['allocations']), 'Không tìm thấy nguồn nợ phí.', 'allocations');
            foreach ($fees as $fee) {
                $this->posting->ensure($fee->shop_id === $data['shop_id'] && $fee->warehouse_id === $data['warehouse_id'] && $data['allocations'][$fee->id] <= $this->ledger->feeAvailable($fee), 'Phân bổ sai shop/kho hoặc vượt nợ phí còn lại.', 'allocations');
            }
            $receipt = FeeReceipt::create($this->posting->identity('TP', $actor, $data, $hash) + ['warehouse_id' => $data['warehouse_id'], 'shop_id' => $data['shop_id'], 'amount' => $data['amount'], 'status' => 'confirmed', 'transaction_reference' => $data['transaction_reference'], 'received_at' => now()]);
            foreach ($data['allocations'] as $id => $amount) {
                $receipt->allocations()->create(['receivable_id' => $id, 'amount' => $amount]);
            }
            $this->evidence->attach($actor, $receipt, $proof);
            $this->posting->event($actor, $receipt, 'fee_received', $data['reason']);

            return $receipt;
        }));
    }
}
