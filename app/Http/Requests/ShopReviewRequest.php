<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ShopReviewRequest extends ReasonRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), ['status' => ['required', Rule::in(['approved', 'rejected', 'suspended'])]]);
    }
}
