<?php

namespace App\Http\Requests;

use App\Models\DeliveryCollection;
use App\Models\DeliveryTask;
use Illuminate\Validation\Rule;

class DeliveryFilterRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:40'], 'type' => ['nullable', Rule::in(array_keys($this->routeIs('delivery.mine') ? DeliveryTask::TYPES : DeliveryCollection::TYPES))],
            'view' => ['nullable', Rule::in(['active', 'history'])], 'financial_status' => ['nullable', Rule::in(['matched', 'disputed'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];
    }
}
