@extends('layouts.portal')
@section('heading', 'Tệp báo cáo')
@section('actions')
    <a href="{{ route('reports.history') }}" class="btn btn-outline-primary"><i data-lucide="arrow-left"></i> Tệp đã xuất</a>
@endsection
@section('content')
    <section class="work-section" data-report-export="{{ route('reports.export', $export) }}" data-export-state="{{ $status }}">
        <h2 class="logistics-code mb-4">{{ $export->code }}</h2>
        <dl class="detail-grid">
            <dt>Trạng thái</dt><dd data-export-label>{{ \App\Models\ExportLog::STATUSES[$status] }}</dd>
            <dt>Loại báo cáo</dt><dd>{{ $export->report_type === 'operations' ? 'Vận hành' : 'Tài chính' }} · {{ strtoupper($export->format) }}</dd>
            <dt>Kỳ báo cáo</dt><dd>{{ $export->filters['from'] }} - {{ $export->filters['until'] }}</dd>
            <dt>Số dòng</dt><dd data-export-count>{{ $export->row_count }}</dd>
            <dt>Hạn tải tệp</dt><dd>{{ $export->expires_at->format('d/m/Y H:i') }}</dd>
        </dl>
        <p class="text-danger" data-export-error>{{ $export->error_message }}</p>
        <a class="btn btn-primary" data-export-download href="{{ $download ?? '#' }}" @if(!$download) hidden @endif><i data-lucide="download"></i> Tải báo cáo</a>
        <a class="btn btn-outline-primary" href="{{ route('reports.index', ['type' => $export->report_type] + $export->filters) }}"><i data-lucide="arrow-left"></i> Về báo cáo</a>
    </section>
@endsection
