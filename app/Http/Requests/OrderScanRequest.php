<?php

namespace App\Http\Requests;

use App\Models\Order;

class OrderScanRequest extends PortalRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Order::class) || $this->user()->hasRole('shipper');
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('code');
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);
        // Chỉ nhận URL tra cứu của ứng dụng; không điều hướng theo URL lấy từ QR.
        $prefix = route('shipping.track-form', ['code' => 'PLACEHOLDER']);
        $prefix = substr($prefix, 0, -strlen('PLACEHOLDER'));
        if (str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }
        $this->merge(['code' => strtoupper($value)]);
    }

    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return [];
        }

        return ['code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9-]+$/']];
    }

    public function messages(): array
    {
        return ['code.required' => 'Vui lòng quét QR hoặc nhập mã vận đơn.',
            'code.*' => 'QR phải là liên kết tra cứu của hệ thống hoặc mã vận đơn hợp lệ.'];
    }
}
