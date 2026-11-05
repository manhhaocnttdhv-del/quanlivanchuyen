<?php

namespace App\Http\Requests;

use App\Models\Complaint;
use App\Models\Incident;
use Illuminate\Validation\Rule;

class CaseFilterRequest extends PortalRequest
{
    public function rules(): array
    {
        $incident = $this->routeIs('incidents.*');

        return ['q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', Rule::in(array_keys($incident ? Incident::STATUSES : Complaint::STATUSES))],
            'type' => ['nullable', Rule::in(array_keys($incident ? Incident::TYPES : Complaint::TYPES))],
            'severity' => ['nullable', Rule::in(array_keys(Incident::SEVERITIES))], 'mine' => ['nullable', 'boolean'], 'overdue' => ['nullable', 'boolean'],
            'order_id' => ['nullable', 'integer', 'min:1']];
    }
}
