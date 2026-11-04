<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class LogisticsRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        $reason = ['required', 'string', 'min:5', 'max:1000'];
        $evidence = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return match ($this->route()->getName()) {
            'logistics.assign' => ['revision' => ['required', 'integer', 'min:1'], 'type' => ['required', Rule::in(['pickup', 'delivery', 'return'])], 'shipper_id' => ['required', 'integer', 'exists:users,id'], 'reason' => $reason],
            'logistics.pickup' => ['successful' => ['required', 'boolean'], 'condition' => ['required', Rule::in(['good', 'damaged'])], 'evidence' => array_replace($evidence, [0 => 'required']), 'reason' => $reason],
            'logistics.prepare' => ['direction' => ['required', Rule::in(['inbound', 'outbound'])], 'reason' => $reason],
            'logistics.confirm' => ['reason' => $reason, 'evidence' => $evidence, 'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'], 'lines' => ['required', 'array', 'min:1', 'max:999'],
                'lines.*' => ['required', 'array:received,condition,weight_grams'], 'lines.*.received' => ['required', 'boolean'], 'lines.*.condition' => ['required', Rule::in(['good', 'damaged'])], 'lines.*.weight_grams' => ['nullable', 'integer', 'min:1', 'max:20000000']],
            'logistics.location' => ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'code' => ['required', 'regex:/^[A-Z0-9-]{1,40}$/'], 'name' => ['required', 'string', 'max:150']],
            'stocktakes.store' => ['warehouse_id' => ['required', 'integer', 'exists:warehouses,id'], 'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'], 'reason' => $reason],
            'stocktakes.submit' => ['finalize' => ['sometimes', 'boolean'], 'lines' => ['sometimes', 'array', 'max:200'], 'lines.*' => ['required', 'array:actual_present,note'], 'lines.*.actual_present' => ['required', 'boolean'], 'lines.*.note' => ['nullable', 'string', 'max:1000'], 'unexpected_note' => ['nullable', 'string', 'max:1000']],
            default => ['reason' => $reason],
        };
    }

    public function attributes(): array
    {
        return parent::attributes() + ['shipper_id' => 'Shipper', 'type' => 'Loại nhiệm vụ', 'evidence' => 'Ảnh bằng chứng', 'location_id' => 'Vị trí kho', 'warehouse_id' => 'Kho', 'lines' => 'Danh sách kiện', 'lines.*.weight_grams' => 'Cân thực tế', 'lines.*.note' => 'Ghi chú kiện'];
    }
}
