<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeePublicationRequest;
use App\Http\Requests\FeeVersionRequest;
use App\Models\FeeVersion;
use App\Services\FeeVersionService;

/** Soạn biểu phí nháp và gửi công bố; FeeVersionService chặn sửa bản đã công bố, trùng kỳ/bậc cân. */
class FeeVersionController extends Controller
{
    public function index()
    {
        return view('portal.fees.index', ['versions' => FeeVersion::withCount('rules')->latest('id')->paginate(15)]);
    }

    public function form(?FeeVersion $version = null)
    {
        $rules = $version?->rules->toArray() ?? [];
        if (! $version) {
            // Config chỉ gợi ý dữ liệu ban đầu; cước thực tế lấy các rule đã lưu và công bố trong DB.
            foreach (config('shipping.routes') as $route => $rate) {
                foreach (config('shipping.services') as $service => $option) {
                    $rules[] = ['route' => $route, 'service' => $service, 'min_weight_grams' => 1, 'max_weight_grams' => 20000, 'base_weight_grams' => 500, 'step_weight_grams' => 500, 'base_fee' => $rate['base'] + $option['surcharge'], 'step_fee' => $rate['step'], 'max_cod' => 20000000];
                }
            }
        }

        $replacements = $version && $version->status === 'draft' && $version->active_from->isFuture()
            ? FeeVersion::where('status', 'published')->where('active_from', '<', $version->active_from)
                ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', $version->active_from))
                ->orderBy('active_from')->pluck('name', 'id') : collect();

        return view('portal.fees.form', compact('version', 'rules', 'replacements'));
    }

    public function save(FeeVersionRequest $request, FeeVersionService $service, ?FeeVersion $version = null)
    {
        $version = $service->save($request->user(), $request->validated(), $version);

        return redirect()->route('fees.edit', $version)->with('status', 'Đã lưu bản nháp biểu phí.');
    }

    public function publish(FeePublicationRequest $request, FeeVersion $version, FeeVersionService $service)
    {
        $service->publish($request->user(), $version, $request->validated('reason'), $request->filled('replaces_version_id') ? $request->integer('replaces_version_id') : null);

        return back()->with('status', 'Đã công bố biểu phí.');
    }
}
