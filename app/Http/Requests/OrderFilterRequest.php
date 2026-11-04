<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Validation\Rule;

class OrderFilterRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Order::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/'],
            'status' => ['nullable', Rule::in(array_keys(Order::STATUSES))],
            'shop_id' => ['nullable', 'integer', 'min:1'], 'area_id' => ['nullable', 'integer', 'min:1'],
            'assigned_shipper_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')]];
    }
}
