<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Support\OrderRules;

class OrderRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->route('order') ? $this->user()->can('update', $this->route('order')) : $this->user()->can('create', Order::class);
    }

    protected function prepareForValidation(): void
    {
        // Bỏ dòng hàng trống để có thể lưu nháp; dòng chỉ nhập một phần vẫn phải báo lỗi.
        if (is_array($this->items)) {
            $this->merge(['items' => array_values(array_filter($this->items, fn ($item) => ! is_array($item) || filled($item['name'] ?? null) || filled($item['quantity'] ?? null)))]);
        }
    }

    public function rules(): array
    {
        return array_merge(OrderRules::details($this->routeIs('orders.change')), [
            'client_token' => [$this->routeIs('orders.store') ? 'required' : 'nullable', 'uuid'],
            'revision' => [$this->route('order') ? 'required' : 'nullable', 'integer', 'min:1'],
            'reason' => [$this->routeIs('orders.change') ? 'required' : 'nullable', 'string', 'min:5', 'max:1000'],
        ]);
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), OrderRules::attributes());
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), OrderRules::messages());
    }
}
