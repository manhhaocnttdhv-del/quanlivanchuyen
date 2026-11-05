<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditService
{
    public function record(?User $actor, Model $subject, string $event, array $before = [], array $after = [], ?string $reason = null): void
    {
        // Chỉ lưu trường được phép; không bao giờ ghi mật khẩu hoặc số tài khoản đầy đủ.
        $allowed = ['name', 'email', 'phone', 'status', 'role_ids', 'warehouse_ids', 'address', 'area_id', 'version', 'bank_name', 'last_four', 'verified_at', 'is_active', 'is_default', 'active_from', 'active_until', 'rules', 'auth_version', 'tracking_code', 'shipping_fee', 'cod_amount', 'fee_payer', 'weight_grams', 'recipient_name', 'recipient_phone', 'recipient_address', 'goods', 'service', 'pickup_address_id', 'fee_version_id', 'type', 'changes', 'revision', 'recipient_total', 'parcel_count', 'items', 'warehouse_id'];
        $allowed = array_merge($allowed, ['custody_status', 'assigned_shipper_id', 'parcels', 'return_authorized_by', 'return_reason', 'resolved_by', 'approved_by', 'code', 'account_type']);
        $allowed = array_merge($allowed, ['order_id', 'delivery_task_id', 'delivery_attempt_id', 'shipper_id', 'return_record_id', 'attempt_number', 'outcome', 'reason_code', 'expected_cod', 'expected_fee', 'actual_cod', 'actual_fee', 'financial_status', 'financial_reason', 'amount', 'expected_amount', 'collected_at', 'attempted_at', 'fee_amount', 'fee_payment', 'delivery_attempt_count', 'return_attempt_count', 'delivery_attempt_limit', 'retry_authorized_attempt', 'financial_hold', 'delivered_at', 'returned_at']);
        $allowed = array_merge($allowed, ['incident_holds', 'blocks_dispatch', 'blocks_reconciliation', 'severity', 'title', 'assigned_to', 'created_by', 'proposal_action_id', 'approved_action_id', 'proposed_amount', 'conclusion', 'closed_at', 'incident_id', 'due_at', 'first_response_at', 'resolution', 'warehouse_item_id', 'delivery_collection_id', 'discrepancy_id']);
        $allowed = array_merge($allowed, ['cash_deposit_id', 'reconciliation_id', 'receivable_id', 'shop_id', 'kind', 'reverses_adjustment_id', 'requested_amount', 'received_amount', 'cod_total', 'fee_total', 'adjustment_total', 'payable_amount', 'debt_amount', 'line_version', 'period_start', 'period_end', 'received_at', 'paid_at', 'approved_at', 'transaction_reference', 'difference', 'unallocated_amount', 'allocated_amount', 'accrued_at', 'shop_bank_account_id']);
        AuditLog::create([
            'actor_id' => $actor?->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'event' => $event, 'old_values' => Arr::only($before, $allowed), 'new_values' => Arr::only($after, $allowed),
            'reason' => $reason, 'request_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);
    }
}
