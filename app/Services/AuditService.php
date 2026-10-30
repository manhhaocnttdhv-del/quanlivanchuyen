<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditService
{
    public function record(?User $actor, Model $subject, string $event, array $before = [], array $after = [], ?string $reason = null): void
    {
        $requestId = request()->attributes->get('audit_request_id');
        if (! $requestId) {
            $requestId = (string) Str::uuid();
            request()->attributes->set('audit_request_id', $requestId);
        }
        // Log va outbox cung transaction; chi phat job khi transaction ngoai cung da commit.
        DB::transaction(function () use ($actor, $subject, $event, $before, $after, $reason, $requestId) {
            $log = AuditLog::create([
                'actor_id' => $actor?->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
                'event' => $event, 'old_values' => $this->sanitize($before), 'new_values' => $this->sanitize($after),
                'reason' => AuditData::text($reason), 'request_id' => $requestId, 'result' => 'succeeded', 'created_at' => now(),
            ]);
            app(NotificationService::class)->stage($log);
        });
    }

    public function sanitize(array $values): array
    {
        // Chi luu truong duoc phep; ap dung lai khi doc log cu tu cac sprint truoc.
        $allowed = ['name', 'email', 'phone', 'status', 'role_ids', 'warehouse_ids', 'address', 'area_id', 'version', 'bank_name', 'last_four', 'verified_at', 'is_active', 'is_default', 'active_from', 'active_until', 'rules', 'auth_version', 'tracking_code', 'shipping_fee', 'cod_amount', 'fee_payer', 'weight_grams', 'recipient_name', 'recipient_phone', 'recipient_address', 'goods', 'service', 'pickup_address_id', 'fee_version_id', 'type', 'changes', 'revision', 'recipient_total', 'parcel_count', 'items', 'warehouse_id'];
        $allowed = array_merge($allowed, ['custody_status', 'assigned_shipper_id', 'parcels', 'return_authorized_by', 'return_reason', 'resolved_by', 'approved_by', 'code']);
        $allowed = array_merge($allowed, ['order_id', 'delivery_task_id', 'delivery_attempt_id', 'shipper_id', 'return_record_id', 'attempt_number', 'outcome', 'reason_code', 'expected_cod', 'expected_fee', 'actual_cod', 'actual_fee', 'financial_status', 'financial_reason', 'amount', 'expected_amount', 'collected_at', 'attempted_at', 'fee_amount', 'fee_payment', 'delivery_attempt_count', 'return_attempt_count', 'delivery_attempt_limit', 'retry_authorized_attempt', 'financial_hold', 'delivered_at', 'returned_at']);
        $allowed = array_merge($allowed, ['incident_holds', 'blocks_dispatch', 'blocks_reconciliation', 'severity', 'title', 'assigned_to', 'created_by', 'proposal_action_id', 'approved_action_id', 'proposed_amount', 'conclusion', 'closed_at', 'incident_id', 'due_at', 'first_response_at', 'resolution', 'warehouse_item_id', 'delivery_collection_id', 'discrepancy_id']);
        $allowed = array_merge($allowed, ['cash_deposit_id', 'reconciliation_id', 'receivable_id', 'shop_id', 'kind', 'reverses_adjustment_id', 'requested_amount', 'received_amount', 'cod_total', 'fee_total', 'adjustment_total', 'payable_amount', 'debt_amount', 'line_version', 'period_start', 'period_end', 'received_at', 'paid_at', 'approved_at', 'transaction_reference', 'difference', 'unallocated_amount', 'allocated_amount', 'accrued_at', 'shop_bank_account_id']);
        $allowed = array_merge($allowed, ['report_type', 'format', 'filters', 'row_count', 'generated_at', 'expires_at']);
        $allowed = array_merge($allowed, ['id', 'parcel_number', 'holder_type', 'holder_user_id', 'warehouse_location_id', 'custody_version', 'actual_weight_grams', 'condition', 'quantity', 'route', 'min_weight_grams', 'max_weight_grams', 'base_weight_grams', 'step_weight_grams', 'base_fee', 'step_fee', 'max_cod', 'return_fee', 'from', 'until', 'group_by', 'metric', 'ledger']);

        return AuditData::sanitize($values, $allowed);
    }
}
