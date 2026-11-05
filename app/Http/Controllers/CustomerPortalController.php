<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest;
use App\Http\Requests\TrackingRequest;
use App\Models\Area;
use App\Models\FeeVersion;
use App\Services\FeeCalculator;
use App\Services\PublicTrackingService;

/** Công cụ công khai: cước lấy từ biểu phí hiệu lực, tra cứu chỉ trả dữ liệu đã rút gọn. */
class CustomerPortalController extends Controller
{
    public function index()
    {
        // Cùng khoảng hiệu lực [active_from, active_until) với bộ tính cước trong Service.
        $version = FeeVersion::with('rules')->where('status', 'published')->where('active_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', now()))->first();

        return view('customer.home', [
            'provinces' => Area::where('is_active', true)->orderBy('name')->get()->keyBy('code')->map(fn ($area) => ['name' => $area->name])->all(),
            'services' => config('shipping.services'),
            'rates' => collect(config('shipping.routes'))->map(function ($route, $key) use ($version) {
                $rule = $version?->rules->first(fn ($rule) => $rule->route === $key && $rule->service === 'standard' && $rule->min_weight_grams === 1);

                return array_merge($route, ['base' => $rule ? (int) $rule->base_fee : null, 'base_weight' => $rule?->base_weight_grams, 'step' => $rule ? (int) $rule->step_fee : null, 'step_weight' => $rule?->step_weight_grams]);
            }), 'feeVersion' => $version?->name,
        ]);
    }

    public function estimate(QuoteRequest $request, FeeCalculator $calculator)
    {
        $input = $request->validated();
        $quote = $calculator->calculate(Area::where('code', $input['origin'])->firstOrFail(), Area::where('code', $input['destination'])->firstOrFail(), (int) $input['weight'], $input['service']);

        return $request->expectsJson() ? response()->json($quote)
            : redirect()->to(route('home').'?tool=estimate#cong-cu')->withInput()->with('quote', $quote);
    }

    public function track(TrackingRequest $request, PublicTrackingService $tracking)
    {
        $input = $request->validated();
        $result = $tracking->track($input['tracking_code'], $input['phone_suffix']);

        // Hỗ trợ cả AJAX và form thường; Service kiểm tra mã + đuôi điện thoại, ẩn dữ liệu riêng tư.
        return $request->expectsJson() ? response()->json($result)
            : redirect()->to(route('home').'#cong-cu')->withInput()->with('tracking', $result);
    }
}
