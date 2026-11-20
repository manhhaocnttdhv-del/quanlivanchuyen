<?php

namespace App\Http\Requests;

use App\Models\DeliveryTask;
use App\Policies\LogisticsPolicy;
use Illuminate\Validation\Rule;

class ShipperMapRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return app(LogisticsPolicy::class)->map($this->user());
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::in(array_keys(DeliveryTask::TYPES))],
            'task' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']];
    }
}
