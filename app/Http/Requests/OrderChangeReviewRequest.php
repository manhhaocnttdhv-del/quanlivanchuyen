<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class OrderChangeReviewRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
