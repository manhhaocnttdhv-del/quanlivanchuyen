<?php

namespace App\Http\Requests;

use App\Models\DeliveryDecision;
use App\Models\ReturnRecord;
use App\Policies\LogisticsPolicy;
use App\Support\DeliveryRules;
use Illuminate\Validation\Rule;

class DeliveryRequest extends PortalRequest
{
    public function authorize(): bool
    {
        $policy = app(LogisticsPolicy::class);

        return $this->routeIs('delivery.record')
            ? $policy->shipper($this->user(), $this->route('task'))
            : $policy->dispatch($this->user(), $this->route('order')->warehouse_id);
    }

    public function rules(): array
    {
        if ($this->routeIs('delivery.record')) {
            $needsPhoto = $this->input('outcome') === 'success' || in_array($this->input('reason_code'), ['damaged', 'lost']);

            return DeliveryRules::attempt($this->route('task')->type) + ['evidence' => [$needsPhoto ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
        }

        return ['decision' => ['required', Rule::in(array_keys(DeliveryDecision::TYPES))], 'revision' => ['required', 'integer', 'min:1'],
            'shop_instruction' => ['required', 'string', 'min:5', 'max:1000'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'fee_payment' => ['required_if:decision,return', 'nullable', Rule::in(array_keys(ReturnRecord::PAYMENTS))]];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['receiver_name' => 'Tên người nhận thực tế', 'actual_cod' => 'COD thực thu', 'actual_fee' => 'Phí thực thu', 'financial_reason' => 'Lý do chênh tiền', 'reason_code' => 'Lý do thất bại', 'evidence' => 'Ảnh bằng chứng', 'shop_instruction' => 'Ý kiến shop', 'fee_payment' => 'Cách thu phí hoàn', 'client_token' => 'Mã xác nhận'];
    }
}
