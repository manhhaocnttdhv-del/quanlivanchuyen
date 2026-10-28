<?php

namespace App\Http\Requests;

class LoginRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['identity' => ['required', 'string', 'max:150'], 'password' => ['required', 'string', 'max:200']];
    }
}
