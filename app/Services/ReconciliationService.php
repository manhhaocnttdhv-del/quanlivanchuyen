<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\DeliveryCollection;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\Shop;
use App\Models\User;
use App\Policies\FinancePolicy;
use App\Support\FinanceRules;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class ReconciliationService
{
    public function __construct(private FinancePosting $posting, private FinanceLedger $ledger, private FinancePolicy $policy) {}

    public function create(User $actor, array $input): Reconciliation
    {
        $data = Validator::make($input + ['cod' => [], 'fees' => [], 'adjustment_ids' => []], FinanceRules::reconciliation())->validate();
        $data['warehouse_id'] = (int) $data['warehouse_id'];
        $data['shop_id'] = (int) $data['shop_id'];
        $data['cod'] = FinanceRules::amounts($data['cod']);
        $data['fees'] = FinanceRules::amounts($data['fees']);
        $data['adjustment_ids'] = array_map('intval', $data['adjustment_ids']);
        sort($data['adjustment_ids']);
        abort_unless($this->policy->staff($actor, $data['warehouse_id']), 403);

        return $this->posting->run(function () use ($actor, $data) {
            $hash = $this->posting->hash($data);
            if ($existing = $this->posting->replay(Reconciliation::class, $actor, $data, $hash)) {
                return $existing;
            }
            $shop = Shop::lockForUpdate()->findOrFail($data['shop_id']);
            $bank = $this->bank($shop);
            $document = Reconciliation::create($this->posting->identity('DS', $actor, $data, $hash) + ['warehouse_id' => $data['warehouse_id'], 'shop_id' => $shop->id, 'shop_bank_account_id' => $bank->id,
                'bank_snapshot' => $this->bankSnapshot($bank), 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'status' => 'draft']);
            $document->refresh();
            $this->writeLines($document, $data);
            $this->posting->event($actor, $document, 'reconciliation_created', $data['reason']);

            return $document;
        });
    }

    public function act(User $actor, Reconciliation $document, string $operation, array $input): void
    {
        $rules = FinanceRules::action() + ($operation === 'replace' ? FinanceRules::selection() : []);
        $data = Validator::make($input + ['cod' => [], 'fees' => [], 'adjustment_ids' => []], $rules)->validate();
        $this->posting->run(function () use ($actor, $document, $operation, $data) {
            $shop = Shop::lockForUpdate()->findOrFail($document->shop_id);
            $document = Reconciliation::lockForUpdate()->findOrFail($document->id);
            abort_unless($this->policy->staff($actor, $document->warehouse_id), 403);
            $this->posting->revision($document, $data);
            $this->posting->ensure(in_array($operation, ['replace', 'submit', 'approve', 'return', 'reject', 'cancel', 'reopen', 'refresh-bank']), 'Thao tác không hợp lệ.');
            if ($operation === 'reopen') {
                abort_unless($this->policy->approve($actor, $document), 403);
                $this->posting->ensure($document->status === 'approved', 'Chỉ mở duyệt lại đối soát đã duyệt.');
            } elseif (in_array($operation, ['approve', 'return', 'reject'])) {
                abort_unless($this->policy->approve($actor, $document), 403);
                $this->posting->ensure($document->status === 'submitted', 'Chỉ xét duyệt bản đang chờ.');
            } else {
                $this->posting->ensure(in_array($document->status, ['draft', 'returned']), 'Chỉ sửa/gửi/hủy bản nháp hoặc bị trả lại.');
            }
            if ($document->approved_at) {
                // Sau lan duyet dau, chi duoc duyet lai ngan hang; giu nguyen tien va nguon da chi/thu.
                $this->posting->ensure(! in_array($operation, ['replace', 'reject', 'cancel']), 'Đối soát từng được duyệt chỉ được cập nhật ngân hàng và duyệt lại.');
            }
            if ($operation === 'replace') {
                $document->line_version++;
                $document->status = 'draft';
                $document->save();
                $data['cod'] = FinanceRules::amounts($data['cod']);
                $data['fees'] = FinanceRules::amounts($data['fees']);
                $this->writeLines($document, $data);
            } elseif (in_array($operation, ['submit', 'approve'])) {
                $this->validateLines($document);
                $bank = $this->bank($shop);
                $this->posting->ensure($bank->id === $document->shop_bank_account_id && $this->bankSnapshot($bank) === $document->bank_snapshot, 'Ngân hàng đã đổi. Trả lại và cập nhật bản nháp trước khi duyệt.', 'reason');
                $document->status = $operation === 'submit' ? 'submitted' : 'approved';
                if ($operation === 'approve') {
                    $document->approved_by = $actor->id;
                    $document->approved_at = now();
                    if (Money::value($document->debt_amount) > 0) {
                        Receivable::firstOrCreate(['reconciliation_id' => $document->id], ['shop_id' => $document->shop_id, 'warehouse_id' => $document->warehouse_id, 'type' => 'reconciliation_debt', 'amount' => $document->debt_amount, 'accrued_at' => now()]);
                    }
                }
            } elseif ($operation !== 'refresh-bank') {
                $document->status = ['return' => 'returned', 'reopen' => 'returned', 'reject' => 'rejected', 'cancel' => 'cancelled'][$operation];
            }
            // Ban nhap duoc cap nhat snapshot ngan hang; ban da duyet khong co endpoint sua.
            if (in_array($operation, ['replace', 'refresh-bank'])) {
                $bank = $this->bank($shop);
                $document->shop_bank_account_id = $bank->id;
                $document->bank_snapshot = $this->bankSnapshot($bank);
            }
            $document->revision++;
            $document->save();
            $this->posting->event($actor, $document, 'reconciliation_'.$operation, $data['reason']);
        });
    }

    public function validateLines(Reconciliation $document): void
    {
        $lines = $document->lines()->get();
        $this->posting->ensure($lines->isNotEmpty(), 'Bản đối soát chưa có dòng.');
        $orders = $this->posting->orders($lines->pluck('order_id')->all());
        foreach ($lines as $line) {
            $this->eligible($orders[$line->order_id], $document);
            $available = match ($line->type) {
                'cod' => $this->ledger->codAvailable(DeliveryCollection::lockForUpdate()->findOrFail($line->delivery_collection_id), $document->id),
                'fee' => $this->ledger->feeAvailable(Receivable::lockForUpdate()->findOrFail($line->receivable_id), $document->id),
                default => $this->compensationAvailable(Adjustment::lockForUpdate()->findOrFail($line->adjustment_id), $document->id),
            };
            $this->posting->ensure(Money::value($line->amount) <= $available, 'Dòng nguồn đã được phân bổ hoặc không còn hợp lệ. Trả lại để cập nhật.');
        }
    }

    private function writeLines(Reconciliation $document, array $data): void
    {
        $collections = DeliveryCollection::whereIn('id', array_keys($data['cod']))->orderBy('id')->lockForUpdate()->get();
        $fees = Receivable::whereIn('id', array_keys($data['fees']))->orderBy('id')->lockForUpdate()->get();
        $adjustments = Adjustment::whereIn('id', $data['adjustment_ids'])->orderBy('id')->lockForUpdate()->get();
        $this->posting->ensure($collections->count() === count($data['cod']) && $fees->count() === count($data['fees']) && $adjustments->count() === count($data['adjustment_ids']), 'Dòng nguồn không tồn tại.', 'cod');
        $orderIds = $collections->pluck('order_id')->merge($fees->pluck('order_id'))->merge($adjustments->pluck('order_id'))->filter()->all();
        $orders = $this->posting->orders($orderIds);
        $cod = $fee = $credit = 0;
        foreach ($collections as $source) {
            $order = $orders[$source->order_id];
            $this->eligible($order, $document);
            $amount = $data['cod'][$source->id];
            $this->posting->ensure($source->type === 'cod' && $amount <= $this->ledger->codAvailable($source, $document->id), 'COD chưa về quỹ đủ hoặc đã được chọn.', 'cod');
            $this->posting->ensure($this->inPeriod($source->collected_at, $document), 'COD nằm ngoài kỳ đã chọn.', 'cod');
            $this->line($document, $order, 'cod', 'delivery_collection_id', $source->id, $amount, 'COD #'.$source->id);
            $cod += $amount;
        }
        foreach ($fees as $source) {
            $this->posting->ensure($source->order_id !== null, 'Nợ sau đối soát chỉ được thu bằng phiếu thu phí.', 'fees');
            $order = $orders[$source->order_id];
            $this->eligible($order, $document);
            $amount = $data['fees'][$source->id];
            $this->posting->ensure($amount <= $this->ledger->feeAvailable($source, $document->id) && $this->inPeriod($source->accrued_at, $document), 'Phí vượt số còn nợ hoặc ngoài kỳ.', 'fees');
            $this->line($document, $order, 'fee', 'receivable_id', $source->id, $amount, Receivable::TYPES[$source->type].' #'.$source->id);
            $fee += $amount;
        }
        foreach ($adjustments as $source) {
            $order = $orders[$source->order_id];
            $this->eligible($order, $document);
            $amount = $this->compensationAvailable($source, $document->id);
            $this->posting->ensure($amount > 0 && $this->inPeriod($source->approved_at, $document), 'Bồi thường đã dùng, bị đảo hoặc ngoài kỳ.', 'adjustment_ids');
            $this->line($document, $order, 'compensation', 'adjustment_id', $source->id, $amount, 'Bồi thường '.$source->code);
            $credit += $amount;
        }
        $this->posting->ensure($cod + $fee + $credit > 0, 'Chọn ít nhất một dòng có số tiền.', 'cod');
        foreach ([$cod, $fee, $credit, $cod + $credit - $fee] as $amount) {
            Money::bounded($amount);
        }
        $document->update(['cod_total' => $cod, 'fee_total' => $fee, 'adjustment_total' => $credit, 'payable_amount' => max(0, $cod + $credit - $fee), 'debt_amount' => max(0, $fee - $cod - $credit)]);
    }

    private function line(Reconciliation $document, Order $order, string $type, string $field, int $id, int $amount, string $label): void
    {
        $document->allLines()->create(['version' => $document->line_version, 'order_id' => $order->id, 'type' => $type, $field => $id, 'amount' => $amount, 'source_label' => $label]);
    }

    private function eligible(Order $order, Reconciliation $document): void
    {
        $this->posting->ensure($order->shop_id === $document->shop_id && $order->warehouse_id === $document->warehouse_id && ! $order->reconciliationBlocked(), 'Đơn phải thuộc đúng shop/kho và không khóa tài chính.');
    }

    private function compensationAvailable(Adjustment $source, ?int $except): int
    {
        if ($source->status !== 'approved' || $source->kind !== 'compensation' || $source->reversal()->exists()) {
            return 0;
        }

        return Money::value($source->amount) - $this->ledger->reserved('adjustment_id', $source->id, $except);
    }

    private function inPeriod($time, Reconciliation $document): bool
    {
        return $time && $time->gte(Carbon::parse($document->period_start->format('Y-m-d'), 'Asia/Ho_Chi_Minh')->startOfDay()->utc()) && $time->lt(Carbon::parse($document->period_end->format('Y-m-d'), 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->utc());
    }

    private function bank(Shop $shop)
    {
        $bank = $shop->currentBankAccount()->lockForUpdate()->first();
        $this->posting->ensure($shop->status === 'approved' && $bank?->verified_at !== null, 'Shop phải hoạt động và ngân hàng hiện hành đã xác minh.', 'shop_id');

        return $bank;
    }

    private function bankSnapshot($bank): array
    {
        return ['id' => $bank->id, 'version' => $bank->version, 'bank_name' => $bank->bank_name, 'holder_name' => $bank->holder_name, 'account_number' => $bank->account_number, 'last_four' => $bank->last_four];
    }
}
