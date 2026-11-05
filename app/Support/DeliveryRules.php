<?php

namespace App\Support;

use App\Models\DeliveryAttempt;
use Illuminate\Validation\Rule;

class DeliveryRules
{
    public static function attempt(string $type): array
    {
        $money = ['integer', 'min:0', 'max:9999999999999'];

        return ['client_token' => ['required', 'uuid'], 'outcome' => ['required', Rule::in(['success', 'failed'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'receiver_name' => ['nullable', 'required_if:outcome,success', 'string', 'max:150'],
            'reason_code' => ['nullable', 'required_if:outcome,failed', Rule::in(array_keys(DeliveryAttempt::REASONS))],
            'actual_cod' => $type === 'return' ? ['prohibited'] : array_merge(['required_if:outcome,success', 'prohibited_unless:outcome,success'], $money),
            'actual_fee' => array_merge(['required_if:outcome,success', 'prohibited_unless:outcome,success'], $money),
            'financial_reason' => ['nullable', 'string', 'min:5', 'max:1000']];
    }
}
