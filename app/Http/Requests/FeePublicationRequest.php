<?php

namespace App\Http\Requests;

class FeePublicationRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('catalog.manage') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000'],
            'replaces_version_id' => ['nullable', 'integer', 'exists:fee_versions,id']];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['replaces_version_id' => 'Biểu phí được thay thế'];
    }
}
