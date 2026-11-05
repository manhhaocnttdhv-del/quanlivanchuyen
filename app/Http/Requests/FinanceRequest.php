<?php

namespace App\Http\Requests;

use App\Policies\FinancePolicy;
use App\Support\FinanceRules;

class FinanceRequest extends PortalRequest
{
    public function authorize(): bool
    {
        $policy = app(FinancePolicy::class);
        $user = $this->user();
        if ($deposit = $this->route('deposit')) {
            return $this->route('operation') === 'allocate'
                ? $policy->staff($user, $deposit->warehouse_id) && $user->id !== $deposit->shipper_id && $user->id !== $deposit->created_by
                : $policy->approve($user, $deposit);
        }
        if ($document = $this->route('reconciliation')) {
            return in_array($this->route('operation'), ['approve', 'return', 'reject', 'reopen'])
                ? $policy->approve($user, $document) : $policy->staff($user, $document->warehouse_id);
        }
        if ($document = $this->route('adjustment')) {
            return $policy->approve($user, $document);
        }
        if ($order = $this->route('order')) {
            return $order->warehouse_id && $policy->staff($user, $order->warehouse_id);
        }
        if ($this->routeIs('finance.deposits.store')) {
            return $policy->deposit($user, (int) $this->input('warehouse_id'), (int) $this->input('shipper_id'));
        }

        return $policy->staff($user, (int) $this->input('warehouse_id'));
    }

    protected function prepareForValidation(): void
    {
        // Trinh duyet khong gui mang rong; van validate tung dong va khoa mang trong Service.
        foreach (['cod', 'fees', 'adjustment_ids', 'allocations'] as $field) {
            if (! $this->has($field)) {
                $this->merge([$field => []]);
            }
        }
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'finance.deposits.store' => FinanceRules::deposit(),
            'finance.deposits.action' => match ($this->route('operation')) {
                'confirm' => FinanceRules::confirmation() + ['evidence' => FinanceRules::evidence(true)],
                'allocate' => FinanceRules::action() + FinanceRules::allocations('allocations'),
                default => FinanceRules::action(),
            },
            'finance.reconciliations.store' => FinanceRules::reconciliation(),
            'finance.reconciliations.action' => FinanceRules::action() + ($this->route('operation') === 'replace' ? FinanceRules::selection() : []),
            'finance.payments.store' => FinanceRules::payment() + ['evidence' => FinanceRules::evidence(true)],
            'finance.receipts.store' => FinanceRules::receipt() + ['evidence' => FinanceRules::evidence(true)],
            'finance.adjustments.store' => FinanceRules::adjustment() + ['evidence' => FinanceRules::evidence(true)],
            default => FinanceRules::action(),
        };
    }

    public function attributes(): array
    {
        return parent::attributes() + ['received_amount' => 'Tiền thực nhận', 'amount' => 'Số tiền', 'allocations' => 'Phân bổ',
            'cod' => 'Dòng COD', 'fees' => 'Dòng phí', 'transaction_reference' => 'Mã giao dịch', 'evidence' => 'Chứng từ',
            'warehouse_id' => 'Kho', 'shipper_id' => 'Shipper', 'shop_id' => 'Shop', 'period_start' => 'Đầu kỳ', 'period_end' => 'Cuối kỳ'];
    }
}
