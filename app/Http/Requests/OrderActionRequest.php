<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OrderActionRequest extends PortalRequest
{
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'reason' => [$this->routeIs('orders.submit') ? 'nullable' : 'required', 'string', 'min:5', 'max:1000'],
            'decision' => [$this->routeIs('orders.review') ? 'required' : 'nullable', Rule::in(['approved', 'needs_info', 'rejected'])],
        ];
    }
}
