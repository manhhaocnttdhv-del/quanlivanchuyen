<?php

namespace App\Http\Requests;

use App\Models\DeliveryTask;
use Illuminate\Validation\Rule;

class LogisticsFilterRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:32'], 'warehouse_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_keys(DeliveryTask::TYPES))], 'status' => ['nullable', Rule::in(array_keys(DeliveryTask::STATUSES))], 'hold' => ['nullable', Rule::in(['1'])]];
    }
}
