@extends('layouts.portal')
{{-- Lịch sử tệp của người yêu cầu; tệp hết hạn giữ bản ghi lịch sử và không còn quyền tải. --}}
@section('heading', 'Tệp báo cáo đã xuất')
@section('actions')
    <a class="btn btn-outline-primary" href="{{ route('reports.home') }}"><i data-lucide="arrow-left"></i> Báo cáo</a>
@endsection
@section('content')
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr><th>Mã tệp</th><th>Báo cáo</th><th>Kỳ</th><th>Định dạng</th><th>Trạng thái</th><th>Yêu cầu lúc</th></tr>
            </thead>
            <tbody>
                @forelse ($exports as $export)
                    <tr>
                        <td><a class="order-code" href="{{ route('reports.export', $export) }}">{{ $export->code }}</a></td>
                        <td>{{ $export->report_type === 'operations' ? 'Vận hành' : 'Tài chính' }}</td>
                        <td>{{ $export->filters['from'] }} - {{ $export->filters['until'] }}</td>
                        <td>{{ strtoupper($export->format) }}</td>
                        <td>{{ \App\Models\ExportLog::STATUSES[$export->expires_at->isPast() ? 'expired' : $export->status] }}</td>
                        <td>{{ $export->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Chưa có tệp báo cáo.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $exports->links() }}
@endsection
