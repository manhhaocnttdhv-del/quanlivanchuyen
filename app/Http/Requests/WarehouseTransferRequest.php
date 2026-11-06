<?php

namespace App\Http\Requests;

use App\Policies\LogisticsPolicy;
use App\Policies\WarehouseTransferPolicy;
use Illuminate\Validation\Rule;

class WarehouseTransferRequest extends PortalRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('transfers.store')) {
            return app(LogisticsPolicy::class)->warehouse($this->user(), $this->route('order')->warehouse_id);
        }

        return app(WarehouseTransferPolicy::class)->receive($this->user(), $this->route('transfer'));
    }

    public function rules(): array
    {
        $common = ['revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:1000']];
        if ($this->routeIs('transfers.store')) {
            return $common + ['destination_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
                'carrier' => ['required', 'string', 'max:150'], 'transport_reference' => ['required', 'string', 'max:150']];
        }

        return $common + ['location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'lines' => ['required', 'array', 'min:1', 'max:999'], 'lines.*' => ['required', 'array:received,condition'],
            'lines.*.received' => ['required', 'boolean'], 'lines.*.condition' => ['required', Rule::in(['good', 'damaged'])]];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['destination_warehouse_id' => 'Kho nhận', 'carrier' => 'Đơn vị / người vận chuyển',
            'transport_reference' => 'Biển số xe / mã chuyến', 'location_id' => 'Vị trí nhập kho', 'evidence' => 'Ảnh bằng chứng', 'lines' => 'Kiểm đếm kiện'];
    }
}
