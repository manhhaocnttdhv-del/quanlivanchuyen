<?php

namespace App\Http\Requests;

use App\Policies\IncidentPolicy;
use App\Support\CaseRules;

class IncidentRequest extends PortalRequest
{
    public function authorize(): bool
    {
        $policy = app(IncidentPolicy::class);
        if ($this->routeIs('incidents.store')) {
            return $policy->createForOrder($this->user(), $this->route('order'), filter_var($this->input('discrepancy_id'), FILTER_VALIDATE_INT) ?: null);
        }

        return match ($this->route('operation')) {
            'review' => $policy->approve($this->user(), $this->route('incident')),
            'note' => $policy->view($this->user(), $this->route('incident')),
            default => $policy->manage($this->user(), $this->route('incident')),
        };
    }

    public function rules(): array
    {
        $creating = $this->routeIs('incidents.store');

        return ($creating ? CaseRules::incident() : CaseRules::incidentAction($this->route('operation'))) +
            ['evidence' => CaseRules::evidence($creating && in_array($this->input('type'), ['lost', 'damaged', 'money']))];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['title' => 'Tiêu đề', 'description' => 'Mô tả sự cố', 'type' => 'Loại sự cố', 'severity' => 'Mức độ', 'evidence' => 'Bằng chứng', 'assigned_to' => 'Người xử lý', 'outcome' => 'Hướng kết luận', 'amount' => 'Số tiền đề xuất', 'conclusion' => 'Kết luận'];
    }
}
