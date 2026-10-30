@extends('layouts.portal')
{{-- Tổng/nhóm tính trên toàn query, chi tiết mới phân trang; xuất giữ bộ lọc đã áp dụng, không chỉ trang hiện tại. --}}
@section('heading', $report['title'])
@section('actions')
    <a class="btn btn-outline-primary" href="{{ route('reports.history') }}"><i data-lucide="clock-3"></i> Tệp đã xuất</a>
@endsection
@section('content')
    @php
        $filters = $report['filters'];
        $type = $report['type'];
        $orderFilters = $type === 'operations' || $filters['ledger'] === 'collections';
        $groupLabels = \App\Services\ReportService::GROUP_LABELS;
    @endphp
    <nav class="order-status-tabs" aria-label="Nhóm báo cáo">
        @foreach (['operations' => 'Vận hành', 'finance' => 'Tài chính'] as $key => $label)
            @if ($policy->view(auth()->user(), $key))
                <a class="{{ $type === $key ? 'active' : '' }}" href="{{ route('reports.index', ['type' => $key, 'from' => $filters['from'], 'until' => $filters['until']]) }}">{{ $label }}</a>
            @endif
        @endforeach
    </nav>
    <form method="get" action="{{ route('reports.index', $type) }}" class="report-filters" data-report-type="{{ $type }}">
        <div class="field-group">
            <label for="report-from">Từ ngày</label>
            <input id="report-from" type="date" name="from" value="{{ old('from', $filters['from']) }}" class="form-control @error('from') is-invalid @enderror" required>
            @error('from')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="field-group">
            <label for="report-until">Đến ngày</label>
            <input id="report-until" type="date" name="until" value="{{ old('until', $filters['until']) }}" class="form-control @error('until') is-invalid @enderror" required>
            @error('until')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        @if ($type === 'operations')
            <div class="field-group">
                <label for="report-metric">Chi tiết chỉ tiêu</label>
                <select id="report-metric" name="metric" class="form-select">
                    @foreach ($metrics as $key => $label)
                        <option value="{{ $key }}" @selected($filters['metric'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @else
            <div class="field-group">
                <label for="report-ledger">Sổ báo cáo</label>
                <select id="report-ledger" name="ledger" class="form-select" data-report-ledger>
                    @foreach ($ledgers as $key => $label)
                        <option value="{{ $key }}" @selected($filters['ledger'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field-group">
            <label for="report-group">Nhóm theo</label>
            <select id="report-group" name="group_by" class="form-select" data-report-group>
                @foreach ($groupLabels as $key => $label)
                    <option value="{{ $key }}" @selected($filters['group_by'] === $key)
                        @disabled((! $orderFilters && in_array($key, ['area', 'shipper'])) || ($type === 'finance' && $filters['ledger'] === 'deposits' && $key !== 'warehouse'))>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
        {{-- Ô không có ý nghĩa với sổ hiện tại phải disabled để form không gửi giá trị lọc cũ. --}}
        @foreach (['warehouse_id' => 'Kho', 'shop_id' => 'Shop', 'area_id' => 'Khu vực', 'shipper_id' => 'Shipper'] as $field => $label)
            @php($disabled = (! $orderFilters && in_array($field, ['area_id', 'shipper_id'])) || ($type === 'finance' && $filters['ledger'] === 'deposits' && $field === 'shop_id'))
            <div class="field-group" @if(in_array($field, ['area_id', 'shipper_id'])) data-report-order-filter @endif @if($field === 'shop_id') data-report-shop-filter @endif @if($disabled) hidden @endif>
                <label for="report-{{ $field }}">{{ $label }}</label>
                <select id="report-{{ $field }}" name="{{ $field }}" class="form-select" @disabled($disabled)>
                    <option value="">Tất cả</option>
                    @foreach ($choices[$field] as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters[$field] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div class="report-filter-actions">
            <button class="btn btn-primary"><i data-lucide="filter"></i> Áp dụng</button>
            <a class="btn btn-outline-primary" href="{{ route('reports.index', $type) }}">Đặt lại</a>
        </div>
    </form>
    <p class="report-period">{{ $report['period_note'] }} <span>Dữ liệu lúc {{ \App\Support\ReportValue::display($report['as_of'], 'date') }}.</span></p>
    <div class="report-metrics">
        @foreach ($report['summary'] as $key => $metric)
            @if ($type === 'operations' && isset($metrics[$key]))
                <a class="report-metric {{ $filters['metric'] === $key ? 'active' : '' }}" href="{{ route('reports.index', ['type' => $type] + array_replace($filters, ['metric' => $key])) }}">
            @else
                <div class="report-metric">
            @endif
                <span>{{ $metric['label'] }}</span>
                <strong>{{ \App\Support\ReportValue::display($metric['value'], $metric['format']) }}</strong>
                @if (isset($metric['detail']))
                    <small>{{ $metric['detail'] }}</small>
                @endif
            @if ($type === 'operations' && isset($metrics[$key]))
                </a>
            @else
                </div>
            @endif
        @endforeach
    </div>
    <section class="work-section">
        <h2 class="mb-3">Tổng hợp theo {{ mb_strtolower($groupLabels[$filters['group_by']]) }}</h2>
        @if ($type === 'operations' && $report['groups']->isNotEmpty())
            @php($maximum = max(1, $report['groups']->max($filters['metric'])))
            <div class="report-bars">
                @foreach ($report['groups']->sortByDesc($filters['metric'])->take(8) as $group)
                    <div>
                        <span>{{ $group['name'] }}</span>
                        <progress max="{{ $maximum }}" value="{{ $group[$filters['metric']] }}" aria-label="{{ $group['name'] }}: {{ $metrics[$filters['metric']] }}"></progress>
                        <strong>{{ $group[$filters['metric']] }}</strong>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="table-responsive">
            <table class="table work-table report-table">
                <thead>
                    <tr>
                        <th>{{ $groupLabels[$filters['group_by']] }}</th>
                        @if ($type === 'finance')
                            <th>Loại / trạng thái</th>
                        @endif
                        @foreach ($report['group_columns'] as $label)
                            <th class="text-end">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['groups'] as $group)
                        <tr>
                            <td>{{ $group['name'] ?: 'Chưa phân công' }}</td>
                            @if ($type === 'finance')
                                <td>{{ $group['category_label'] }}</td>
                            @endif
                            @foreach ($report['group_columns'] as $key => $label)
                                <td class="text-end">{{ \App\Support\ReportValue::display($group[$key], $type === 'finance' ? 'money' : 'number') }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($report['group_columns']) + ($type === 'finance' ? 2 : 1) }}">Không có dữ liệu phù hợp.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="work-section">
        <div class="report-section-heading">
            <h2>{{ $report['detail_title'] }}</h2>
            <span>{{ $report['rows']->total() }} dòng</span>
        </div>
        <div class="table-responsive">
            <table class="table work-table report-table">
                <thead>
                    <tr>
                        @foreach ($report['columns'] as $column)
                            <th @class(['text-end' => in_array($column['format'], ['money', 'number'])])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            @foreach ($report['columns'] as $key => $column)
                                <td @class(['text-end' => in_array($column['format'], ['money', 'number'])])>
                                    @if ($key === 'code' && $row->source_url)
                                        <a class="order-code" href="{{ $row->source_url }}">{{ $row->code }}</a>
                                    @else
                                        {{ \App\Support\ReportValue::display($key === 'category' ? $row->category_label : ($row->$key ?? null), $column['format']) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($report['columns']) }}">Không có dữ liệu phù hợp.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $report['rows']->links() }}
    </section>
    <form method="post" action="{{ route('reports.store', $type) }}" class="report-export-form">
        @csrf
        <input type="hidden" name="client_token" value="{{ \Illuminate\Support\Str::uuid() }}">
        @foreach ($filters as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <div class="field-group">
            <label for="report-format">Định dạng</label>
            <select id="report-format" name="format" class="form-select @error('format') is-invalid @enderror">
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="pdf" @selected(old('format') === 'pdf')>PDF</option>
            </select>
            @error('format')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button class="btn btn-primary"><i data-lucide="download"></i> Xuất báo cáo</button>
    </form>
@endsection
