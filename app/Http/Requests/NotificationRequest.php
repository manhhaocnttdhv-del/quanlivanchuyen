<?php

namespace App\Http\Requests;

use App\Support\NotificationEvents;
use Illuminate\Validation\Rule;

class NotificationRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return [
            'state' => ['nullable', Rule::in(['all', 'unread', 'read'])],
            'category' => ['nullable', Rule::in(array_keys(NotificationEvents::CATEGORIES))],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'read' => [$this->isMethod('patch') ? 'required' : 'sometimes', 'boolean'],
        ];
    }
}
