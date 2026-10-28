<?php

namespace App\Http\Requests;

class BankAccountRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['bank_name' => ['required', 'string', 'max:100'], 'holder_name' => ['required', 'string', 'max:100'], 'account_number' => ['required', 'regex:/^[0-9]{6,30}$/']];
    }
}
