<?php

namespace App\Support;

use App\Models\Complaint;
use App\Models\Incident;
use Illuminate\Validation\Rule;

class CaseRules
{
    public static function evidence(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
    }

    public static function incident(): array
    {
        return ['client_token' => ['required', 'uuid'], 'title' => ['required', 'string', 'min:5', 'max:180'], 'description' => ['required', 'string', 'min:10', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Incident::TYPES))], 'severity' => ['required', Rule::in(array_keys(Incident::SEVERITIES))],
            'blocks_dispatch' => ['required', 'boolean'], 'blocks_reconciliation' => ['required', 'boolean'],
            'warehouse_item_id' => ['nullable', 'integer'], 'delivery_task_id' => ['nullable', 'integer'], 'delivery_collection_id' => ['nullable', 'integer'], 'discrepancy_id' => ['nullable', 'integer']];
    }

    public static function complaint(): array
    {
        return ['client_token' => ['required', 'uuid'], 'title' => ['required', 'string', 'min:5', 'max:180'], 'description' => ['required', 'string', 'min:10', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Complaint::TYPES))]];
    }

    public static function incidentAction(string $operation): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:2000']];

        return $rules + match ($operation) {
            'assign' => ['assigned_to' => ['required', 'integer']],
            'hold' => ['blocks_dispatch' => ['required', 'boolean'], 'blocks_reconciliation' => ['required', 'boolean']],
            'propose' => ['outcome' => ['required', Rule::in(array_keys(Incident::OUTCOMES))], 'amount' => ['required', 'integer', 'min:0', 'max:9999999999999'], 'conclusion' => ['required', 'string', 'min:10', 'max:5000']],
            'review' => ['decision' => ['required', Rule::in(['approved', 'rejected'])]],
            default => [],
        };
    }

    public static function complaintAction(string $operation): array
    {
        return ['revision' => ['required', 'integer', 'min:1']] + match ($operation) {
            'assign' => ['assigned_to' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:2000']],
            'link' => ['incident_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:5', 'max:2000']],
            'close' => ['resolution' => ['required', 'string', 'min:10', 'max:5000']],
            'reopen' => ['reason' => ['required', 'string', 'min:10', 'max:2000']],
            default => ['body' => ['required', 'string', 'min:2', 'max:5000'], 'is_internal' => ['required', 'boolean']],
        };
    }
}
