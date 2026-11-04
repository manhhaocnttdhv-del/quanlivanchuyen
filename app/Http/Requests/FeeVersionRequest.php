<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class FeeVersionRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'active_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'active_until' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:active_from'],
            'rules' => ['required', 'array', 'min:6', 'max:60'],
            'rules.*' => ['array:route,service,min_weight_grams,max_weight_grams,base_weight_grams,step_weight_grams,base_fee,step_fee,max_cod'],
            'rules.*.route' => ['required', Rule::in(['local', 'regional', 'national'])],
            'rules.*.service' => ['required', Rule::in(['standard', 'express'])],
            'rules.*.min_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.max_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.base_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.step_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.base_fee' => ['required', 'integer', 'between:0,10000000'],
            'rules.*.step_fee' => ['required', 'integer', 'between:0,10000000'],
            'rules.*.max_cod' => ['required', 'integer', 'between:0,100000000']];
    }
}
