<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReportExportRequest extends ReportRequest
{
    public function rules(): array
    {
        return parent::rules() + ['format' => ['required', Rule::in(['xlsx', 'pdf'])], 'client_token' => ['required', 'uuid']];
    }
}
