<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page { margin: 35px 25px 35px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #203635; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        h2 { font-size: 12px; margin: 20px 0 8px; }
        p { margin: 6px 0; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; margin-top: 10px; }
        th { background: #eaf4ef; color: #075d55; text-align: left; }
        th, td { padding: 6px 4px; border: 1px solid #dce5e3; vertical-align: top; word-wrap: break-word; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .number { text-align: right; }
        .summary { width: 65%; }
        .muted { color: #5c6e70; }
    </style>
</head>
<body>
    <h1>Gửi Việt · {{ $report['title'] }}</h1>
    <p>{{ $report['filters']['from'] }} - {{ $report['filters']['until'] }} · {{ count($report['rows']) }} dòng</p>
    <p>{{ implode(' · ', $report['filter_labels']) }}</p>
    <p class="muted">{{ $report['period_note'] }}</p>
    <p class="muted">Dữ liệu lúc {{ \App\Support\ReportValue::display($report['as_of'], 'date') }} · {{ config('app.timezone') }}</p>

    <table class="summary">
        <thead>
            <tr><th>Chỉ tiêu</th><th class="number">Giá trị</th></tr>
        </thead>
        <tbody>
            @foreach ($report['summary'] as $metric)
                <tr>
                    <td>
                        {{ $metric['label'] }}
                        @if (isset($metric['detail']))
                            <br>{{ $metric['detail'] }}
                        @endif
                    </td>
                    <td class="number">{{ \App\Support\ReportValue::display($metric['value'], $metric['format']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Tổng hợp theo {{ mb_strtolower(\App\Services\ReportService::GROUP_LABELS[$report['filters']['group_by']]) }}</h2>
    <table>
        <thead>
            <tr>
                <th>Nhóm</th>
                @if ($report['type'] === 'finance')
                    <th>Loại / trạng thái</th>
                @endif
                @foreach ($report['group_columns'] as $label)
                    <th class="number">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['groups'] as $group)
                <tr>
                    <td>{{ $group['name'] ?? 'Chưa phân công' }}</td>
                    @if ($report['type'] === 'finance')
                        <td>{{ $group['category_label'] }}</td>
                    @endif
                    @foreach ($report['group_columns'] as $key => $label)
                        <td class="number">{{ \App\Support\ReportValue::display($group[$key], $report['type'] === 'finance' ? 'money' : 'number') }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['group_columns']) + ($report['type'] === 'finance' ? 2 : 1) }}">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Chi tiết · {{ $report['detail_title'] }}</h2>
    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $key => $column)
                        <td @class(['number' => in_array($column['format'], ['money', 'number'])])>
                            {{ \App\Support\ReportValue::display($key === 'category' ? $row->category_label : ($row->$key ?? null), $column['format']) }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}">Không có dữ liệu.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
