<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use App\Services\AuditSubject;
use Illuminate\Validation\Rule;

class AuditFilterRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', AuditLog::class);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'subject_type' => ['nullable', Rule::in(array_keys(AuditSubject::LABELS))],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'request_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
