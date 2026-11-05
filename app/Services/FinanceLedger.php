<?php

namespace App\Services;

use App\Models\AdjustmentLine;
use App\Models\CashDeposit;
use App\Models\DeliveryCollection;
use App\Models\DepositAllocation;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\ReconciliationLine;
use App\Support\Money;

class FinanceLedger
{
    public function corrections(string $field, int $id): int
    {
        return Money::value(AdjustmentLine::where($field, $id)->whereHas('adjustment', fn ($q) => $q->where('status', 'approved'))->sum('amount'));
    }

    public function effective(DeliveryCollection $collection): int
    {
        // Dieu chinh chi cong vao gia tri hieu luc, khong ghi de khoan thu goc.
        return Money::value($collection->amount) + $this->corrections('delivery_collection_id', $collection->id);
    }

    public function deposited(int $id): int
    {
        return Money::value(DepositAllocation::where('delivery_collection_id', $id)->sum('amount'));
    }

    public function held(DeliveryCollection $collection): int
    {
        return $this->effective($collection) - $this->deposited($collection->id);
    }

    public function reserved(string $field, int $id, ?int $except = null): int
    {
        // Chi phien ban hien hanh giu cho tien; ban tra lai van giu de tranh doi soat hai lan.
        return Money::value(ReconciliationLine::where($field, $id)->whereHas('reconciliation', fn ($q) => $q->whereIn('status', Reconciliation::RESERVING_STATUSES)->when($except, fn ($q) => $q->whereKeyNot($except))->whereColumn('reconciliations.line_version', 'reconciliation_lines.version'))->sum('amount'));
    }

    public function codAvailable(DeliveryCollection $collection, ?int $except = null): int
    {
        // COD chi duoc tra shop khi tien da ve quy; khong dua vao tien shipper con giu.
        return min($this->effective($collection), $this->deposited($collection->id)) - $this->reserved('delivery_collection_id', $collection->id, $except);
    }

    public function feeAvailable(Receivable $fee, ?int $except = null): int
    {
        return Money::value($fee->amount) + $this->corrections('receivable_id', $fee->id) - Money::value($fee->receiptAllocations()->sum('amount')) - $this->reserved('receivable_id', $fee->id, $except);
    }

    public function paid(int $id): int
    {
        return Money::value(PaymentAllocation::where('reconciliation_id', $id)->sum('amount'));
    }

    public function unallocated(CashDeposit $deposit): int
    {
        return Money::value($deposit->received_amount) - Money::value($deposit->allocations()->sum('amount'));
    }
}
