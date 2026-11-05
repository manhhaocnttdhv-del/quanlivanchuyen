<?php

namespace App\Http\Requests;

use App\Policies\ReportPolicy;
use App\Services\ReportScope;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ReportRequest extends PortalRequest
{
    public const FILTERS = ['from', 'until', 'group_by', 'metric', 'ledger', 'warehouse_id', 'shop_id', 'area_id', 'shipper_id'];

    public function authorize(): bool
    {
        return app(ReportPolicy::class)->view($this->user(), $this->route('type'));
    }

    protected function prepareForValidation(): void
    {
        $today = now('Asia/Ho_Chi_Minh');
        $this->merge(array_merge([
            'from' => $today->copy()->startOfMonth()->toDateString(), 'until' => $today->toDateString(),
            'group_by' => 'warehouse', 'metric' => 'created', 'ledger' => 'collections',
        ], $this->all()));
    }

    public function rules(): array
    {
        $orderFilters = $this->route('type') === 'operations' || $this->input('ledger') === 'collections';
        $deposits = $this->route('type') === 'finance' && $this->input('ledger') === 'deposits';
        $ledgers = ['collections', 'fees', 'reconciliations', 'payments'];
        if (app(ReportPolicy::class)->staff($this->user(), 'finance')) {
            $ledgers[] = 'deposits';
        }

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['required', Rule::in($deposits ? ['warehouse'] : ($orderFilters ? ['warehouse', 'shop', 'area', 'shipper'] : ['warehouse', 'shop']))],
            'metric' => ['required', Rule::in(['created', 'received', 'delivered', 'returned', 'failed', 'inventory', 'overdue'])],
            'ledger' => ['required', Rule::in($ledgers)],
            'warehouse_id' => ['nullable', 'integer', 'min:1'], 'shop_id' => $deposits ? ['prohibited'] : ['nullable', 'integer', 'min:1'],
            'area_id' => $orderFilters ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'shipper_id' => $orderFilters ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->hasAny(['from', 'until']) && Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('until'))) > 365) {
                $validator->errors()->add('until', 'Mỗi báo cáo tối đa 366 ngày.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        app(ReportScope::class)->validate($this->user(), $this->route('type'), $this->filters());
    }

    public function filters(): array
    {
        return array_filter(Arr::only($this->validated(), self::FILTERS), fn ($value) => $value !== null && $value !== '');
    }

    public function attributes(): array
    {
        return parent::attributes() + ['from' => 'Từ ngày', 'until' => 'Đến ngày', 'group_by' => 'Nhóm dữ liệu',
            'metric' => 'Chỉ tiêu', 'ledger' => 'Sổ báo cáo', 'warehouse_id' => 'Kho', 'shop_id' => 'Shop', 'shipper_id' => 'Shipper'];
    }
}
