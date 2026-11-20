<?php

namespace App\Services;

use App\Models\FeeVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeeVersionService
{
    public function __construct(private AuditService $audit) {}

    public function validateRules(array $rules): void
    {
        foreach (['local', 'regional', 'national'] as $route) {
            foreach (['standard', 'express'] as $service) {
                $group = collect($rules)->where('route', $route)->where('service', $service)->sortBy('min_weight_grams');
                $next = 1;
                foreach ($group as $rule) {
                    if ((int) $rule['min_weight_grams'] !== $next || $rule['max_weight_grams'] < $rule['min_weight_grams']
                        || $rule['base_weight_grams'] < $rule['min_weight_grams'] || $rule['base_weight_grams'] > $rule['max_weight_grams']) {
                        throw ValidationException::withMessages(['rules' => 'Mỗi tuyến/dịch vụ phải phủ liên tục 1–20.000g; bậc cân không được hở hoặc chồng lấn, cân cơ bản phải nằm trong bậc.']);
                    }
                    $next = (int) $rule['max_weight_grams'] + 1;
                }
                if ($next !== 20001) {
                    throw ValidationException::withMessages(['rules' => 'Cần đủ 6 cặp tuyến/dịch vụ, mỗi cặp phủ từ 1 đến 20.000g.']);
                }
            }
        }
    }

    public function save(User $actor, array $data, ?FeeVersion $version = null): FeeVersion
    {
        $this->validateRules($data['rules']);

        return DB::transaction(function () use ($actor, $data, $version) {
            $version = $version ? FeeVersion::lockForUpdate()->findOrFail($version->id) : new FeeVersion;
            if ($version->exists && $version->status !== 'draft') {
                throw ValidationException::withMessages(['name' => 'Biểu phí đã công bố không được sửa. Hãy tạo phiên bản mới.']);
            }
            $before = $version->only(['name', 'active_from', 'active_until']);
            $version->fill(Arr::only($data, ['name', 'active_from', 'active_until']));
            if (! $version->exists) {
                $version->created_by = $actor->id;
            }
            $version->save();
            $version->rules()->delete();
            $version->rules()->createMany($data['rules']);
            $this->audit->record($actor, $version, 'fee.saved', $before, $data);

            return $version;
        });
    }

    public function publish(User $actor, FeeVersion $version, string $reason, ?int $replacesVersionId = null): void
    {
        DB::transaction(function () use ($actor, $version, $reason, $replacesVersionId) {
            DB::table('business_locks')->where('name', 'fee-publication')->lockForUpdate()->first();
            $version = FeeVersion::lockForUpdate()->findOrFail($version->id);
            if ($version->status !== 'draft') {
                throw ValidationException::withMessages(['reason' => 'Phiên bản đã được công bố.']);
            }
            $this->validateRules($version->rules->toArray());
            $previous = $replacesVersionId ? FeeVersion::lockForUpdate()->findOrFail($replacesVersionId) : null;
            if ($previous && ($previous->id === $version->id || $previous->status !== 'published'
                || ! $version->active_from->isFuture() || ! $previous->active_from->lt($version->active_from)
                || ($previous->active_until && ! $previous->active_until->gt($version->active_from)))) {
                throw ValidationException::withMessages(['replaces_version_id' => 'Chỉ thay thế biểu phí đã công bố còn hiệu lực tại thời điểm bắt đầu mới trong tương lai.']);
            }
            // Khoảng hiệu lực là [từ, đến); cho phép phiên bản sau bắt đầu đúng lúc bản trước kết thúc.
            $overlap = FeeVersion::where('status', 'published')->where('id', '!=', $version->id)
                ->when($previous, fn ($q) => $q->whereKeyNot($previous->id))
                ->when($version->active_until, fn ($q) => $q->where('active_from', '<', $version->active_until))
                ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', $version->active_from))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['active_from' => 'Thời gian hiệu lực trùng một biểu phí đã công bố.']);
            }
            if ($previous) {
                // Kết thúc bản cũ và công bố bản mới cùng commit, không tạo khoảng trống giữa hai mức phí.
                $before = ['active_from' => $previous->active_from->toDateTimeString(), 'active_until' => $previous->active_until?->toDateTimeString()];
                $previous->update(['active_until' => $version->active_from]);
                $this->audit->record($actor, $previous, 'fee.superseded', $before, ['active_until' => $previous->active_until->toDateTimeString()], $reason.' · Thay bằng '.$version->name);
            }
            $version->status = 'published';
            $version->published_by = $actor->id;
            $version->published_at = now();
            $version->save();
            $this->audit->record($actor, $version, 'fee.published', ['status' => 'draft'], ['status' => 'published'], $reason);
        });
    }
}
