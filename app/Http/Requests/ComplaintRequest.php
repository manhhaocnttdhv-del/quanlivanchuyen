<?php

namespace App\Http\Requests;

use App\Policies\ComplaintPolicy;
use App\Support\CaseRules;

class ComplaintRequest extends PortalRequest
{
    public function authorize(): bool
    {
        $policy = app(ComplaintPolicy::class);
        if ($this->routeIs('complaints.store')) {
            return $policy->createForOrder($this->user(), $this->route('order'));
        }

        return in_array($this->route('operation'), ['message', 'reopen'])
            ? $policy->view($this->user(), $this->route('complaint'))
            : $policy->manage($this->user(), $this->route('complaint'));
    }

    public function rules(): array
    {
        return ($this->routeIs('complaints.store') ? CaseRules::complaint() : CaseRules::complaintAction($this->route('operation'))) +
            ['evidence' => CaseRules::evidence()];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['title' => 'Tiêu đề', 'description' => 'Nội dung khiếu nại', 'type' => 'Loại khiếu nại', 'evidence' => 'Tệp đính kèm', 'body' => 'Nội dung trao đổi', 'assigned_to' => 'Người xử lý', 'incident_id' => 'Sự cố liên quan', 'resolution' => 'Kết quả xử lý'];
    }
}
