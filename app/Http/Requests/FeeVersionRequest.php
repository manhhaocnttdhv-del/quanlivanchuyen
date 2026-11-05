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
            'rules.*' => ['array:route,service,min_weight_grams,max_weight_grams,base_weight_grams,step_weight_grams,base_fee,step_fee,max_cod,return_fee'],
            'rules.*.route' => ['required', Rule::in(['local', 'regional', 'national'])],
            'rules.*.service' => ['required', Rule::in(['standard', 'express'])],
            'rules.*.min_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.max_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.base_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.step_weight_grams' => ['required', 'integer', 'between:1,20000'],
            'rules.*.base_fee' => ['required', 'integer', 'between:0,10000000'],
            'rules.*.step_fee' => ['required', 'integer', 'between:0,10000000'],
            'rules.*.return_fee' => ['sometimes', 'integer', 'between:0,10000000'],
            'rules.*.max_cod' => ['required', 'integer', 'between:0,100000000']];
    }

    protected function prepareForValidation(): void
    {
        // Xóa dòng trên UI có thể để hở chỉ số; old input và lỗi phải cùng thứ tự với Blade.
        if (is_array($this->input('rules'))) {
            $this->merge(['rules' => array_values($this->input('rules'))]);
        }
    }

    public function attributes(): array
    {
        return parent::attributes() + ['active_from' => 'Hiệu lực từ', 'active_until' => 'Hiệu lực đến', 'rules' => 'Bậc cân',
            'rules.*.min_weight_grams' => 'Cân nặng từ', 'rules.*.max_weight_grams' => 'Cân nặng đến',
            'rules.*.base_weight_grams' => 'Cân cơ bản', 'rules.*.step_weight_grams' => 'Bước cân',
            'rules.*.base_fee' => 'Phí cơ bản', 'rules.*.step_fee' => 'Phí mỗi bước',
            'rules.*.max_cod' => 'COD tối đa', 'rules.*.return_fee' => 'Phí hoàn'];
    }
}
