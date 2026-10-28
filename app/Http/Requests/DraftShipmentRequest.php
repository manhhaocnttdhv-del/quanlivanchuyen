<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class DraftShipmentRequest extends QuoteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sender_name' => ['required', 'string', 'min:2', 'max:100'],
            'sender_phone' => ['required', 'regex:/^0[0-9]{9}$/'],
            'sender_address' => ['required', 'string', 'min:5', 'max:255'],
            'recipient_name' => ['required', 'string', 'min:2', 'max:100'],
            'recipient_phone' => ['required', 'regex:/^0[0-9]{9}$/'],
            'recipient_address' => ['required', 'string', 'min:5', 'max:255'],
            'goods' => ['required', 'string', 'max:200'],
            'cod' => ['required', 'integer', 'between:0,20000000'],
            'fee_payer' => ['required', Rule::in(['sender', 'recipient'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
