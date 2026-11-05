<?php

namespace App\Http\Requests;

use App\Policies\FinancePolicy;

class FinanceFilterRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return app(FinancePolicy::class)->any($this->user());
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:40'], 'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'shipper_id' => ['nullable', 'integer', 'min:1'], 'shop_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:30'], 'type' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'order_id' => ['nullable', 'integer', 'min:1']];
    }
}
