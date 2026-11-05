<?php

namespace App\Services;

use App\Models\Area;
use App\Models\FeeVersion;
use Illuminate\Validation\ValidationException;

class FeeCalculator
{
    public function calculate(Area $origin, Area $destination, int $grams, string $service, int $cod = 0, string $payer = 'sender'): array
    {
        if (! $origin->is_active || ! $destination->is_active || $grams < 1 || $grams > 20000 || $cod < 0 || ! in_array($payer, ['sender', 'recipient'])) {
            throw ValidationException::withMessages(['weight_grams' => 'Kiểm tra khu vực, khối lượng 1–20.000g, COD và người trả phí.']);
        }
        $version = FeeVersion::where('status', 'published')->where('active_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', now()))->first();
        if (! $version) {
            throw ValidationException::withMessages(['service' => 'Chưa có biểu phí đang hiệu lực. Liên hệ điều phối.']);
        }
        $route = $origin->id === $destination->id ? 'local' : ($origin->region === $destination->region ? 'regional' : 'national');
        $rule = $version->rules()->where('route', $route)->where('service', $service)->where('min_weight_grams', '<=', $grams)->where('max_weight_grams', '>=', $grams)->first();
        if (! $rule || $cod > (int) $rule->max_cod) {
            throw ValidationException::withMessages(['cod_amount' => 'Không có bậc phí phù hợp hoặc COD vượt mức cho phép.']);
        }
        // Số nguyên đồng và gram: làm tròn lên từng bước cân, không dùng float để tính tiền.
        $steps = intdiv(max(0, $grams - $rule->base_weight_grams) + $rule->step_weight_grams - 1, $rule->step_weight_grams);
        $base = (int) $rule->base_fee;
        $extra = $steps * (int) $rule->step_fee;
        $fee = $base + $extra;

        return [
            'version_id' => $version->id, 'rule_id' => $rule->id, 'version' => $version->name,
            'route_key' => $route, 'route' => config("shipping.routes.$route.label"),
            'service' => config("shipping.services.$service.label"), 'days' => $service === 'express' ? '1 - 2 ngày' : config("shipping.routes.$route.days"),
            'base_weight_grams' => $rule->base_weight_grams, 'step_weight_grams' => $rule->step_weight_grams,
            'step_fee' => (int) $rule->step_fee, 'steps' => $steps, 'base_fee' => $base, 'weight_fee' => $extra,
            'total' => $fee, 'max_cod' => (int) $rule->max_cod, 'cod_amount' => $cod, 'fee_payer' => $payer,
            'recipient_total' => $cod + ($payer === 'recipient' ? $fee : 0),
            'calculated_at' => now()->toIso8601String(),
            'return_fee' => (int) $rule->return_fee,
        ];
    }
}
