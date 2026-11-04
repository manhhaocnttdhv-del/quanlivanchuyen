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
        $allowed = array_merge($allowed, ['custody_status', 'assigned_shipper_id', 'parcels', 'return_authorized_by', 'return_reason', 'resolved_by', 'approved_by', 'code']);
        AuditLog::create([
            'actor_id' => $actor?->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'event' => $event, 'old_values' => Arr::only($before, $allowed), 'new_values' => Arr::only($after, $allowed),
            'reason' => $reason, 'request_id' => (string) Str::uuid(), 'created_at' => now(),
        ]);
    }
}
