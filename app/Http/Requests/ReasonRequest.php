<?php

namespace App\Http\Requests;

class ReasonRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
