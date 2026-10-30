<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Reconciliation;
use App\Models\Shop;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Support\FinanceRules;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class PaymentService
{
    public function __construct(private FinancePosting $posting, private FinanceLedger $ledger, private FinancePolicy $policy, private FinanceEvidence $evidence, private ReconciliationService $reconciliations) {}

    public function record(User $actor, Reconciliation $document, array $input, ?UploadedFile $file): Payment
    {
        $data = Validator::make($input, FinanceRules::payment())->validate();
        $data['amount'] = Money::value($data['amount']);
        abort_unless($this->policy->staff($actor, $document->warehouse_id), 403);

        return $this->evidence->run($file, true, fn ($proof) => $this->posting->run(function () use ($actor, $document, $data, $proof) {
            $shop = Shop::lockForUpdate()->findOrFail($document->shop_id);
            $document = Reconciliation::lockForUpdate()->findOrFail($document->id);
            abort_unless($this->policy->staff($actor, $document->warehouse_id) && $shop->user_id !== $actor->id, 403);
            $hash = $this->posting->hash($data + ['reconciliation_id' => $document->id], $proof);
            if ($existing = $this->posting->replay(Payment::class, $actor, $data, $hash)) {
                return $existing;
            }
            $this->posting->ensure($document->status === 'approved', 'Đối soát chưa được duyệt.');
            $this->reconciliations->validateLines($document);
            $bank = $document->bank()->lockForUpdate()->firstOrFail();
            $snapshot = $document->bank_snapshot;
            $this->posting->ensure($shop->status === 'approved' && $shop->current_bank_account_id === $document->shop_bank_account_id && $bank->verified_at &&
                $bank->version === $snapshot['version'] && $bank->bank_name === $snapshot['bank_name'] && $bank->holder_name === $snapshot['holder_name'] && $bank->account_number === $snapshot['account_number'],
                'Ngân hàng/shop đã thay đổi sau duyệt. Tạm ngừng chi và mở quy trình duyệt lại ngân hàng.');
            $this->posting->ensure($data['amount'] <= Money::value($document->payable_amount) - $this->ledger->paid($document->id), 'Số thanh toán vượt số còn phải trả.', 'amount');
            $this->posting->referenceAvailable($data['transaction_reference']);
            $payment = Payment::create($this->posting->identity('TT', $actor, $data, $hash) + ['reconciliation_id' => $document->id, 'warehouse_id' => $document->warehouse_id, 'status' => $data['status'], 'amount' => $data['amount'], 'transaction_reference' => $data['transaction_reference'], 'paid_at' => now()]);
            if ($data['status'] === 'succeeded') {
                $payment->allocation()->create(['reconciliation_id' => $document->id, 'amount' => $data['amount']]);
            }
            $this->evidence->attach($actor, $payment, $proof);
            $this->posting->event($actor, $payment, 'payment_'.$data['status'], $data['reason']);

            return $payment;
        }));
    }
}
