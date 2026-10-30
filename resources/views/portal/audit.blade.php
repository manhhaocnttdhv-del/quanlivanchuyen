@extends('layouts.portal')
@section('heading', 'Nhật ký thay đổi')
@section('content')
    <form class="audit-filters" method="get">
        <x-field name="q" label="Mã vận đơn / sự kiện / ID" :value="request('q')" />
        <x-field name="actor_id" label="Người thao tác" :value="request('actor_id')" :options="$actors" placeholder="Tất cả" />
        <x-field name="subject_type" label="Loại đối tượng" :value="request('subject_type')" :options="$subjects" placeholder="Tất cả" />
        <x-field name="subject_id" label="ID đối tượng" type="number" min="1" :value="request('subject_id')" />
        <x-field name="from" label="Từ ngày" type="date" :value="request('from')" />
        <x-field name="until" label="Đến ngày" type="date" :value="request('until')" />
        <x-field name="event" label="Sự kiện chính xác" :value="request('event')" />
        <x-field name="request_id" label="Mã request" :value="request('request_id')" />
        <div class="report-filter-actions">
            <button class="btn btn-primary"><i data-lucide="search"></i> Tìm nhật ký</button>
            <a class="btn btn-outline-primary" href="{{ route('audit.index') }}">Đặt lại</a>
        </div>
    </form>
    <div class="audit-result-count">{{ $logs->total() }} sự kiện</div>
    <div class="table-responsive">
        <table class="table work-table audit-table">
            <thead>
                <tr><th>Thời gian</th><th>Người thao tác</th><th>Sự kiện</th><th>Đối tượng</th><th>Kết quả</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $log->actor?->name ?? 'Hệ thống' }}</td>
                        <td><a class="order-code" href="{{ route('audit.show', $log) }}">{{ $log->event }}</a></td>
                        <td>{{ $subjects[class_basename($log->subject_type)] ?? class_basename($log->subject_type) }} #{{ $log->subject_id }}</td>
                        <td><span class="status-badge">Thành công</span></td>
                        <td><a class="btn icon-button" href="{{ route('audit.show', $log) }}" aria-label="Xem sự kiện #{{ $log->id }}" title="Chi tiết sự kiện"><i data-lucide="arrow-up-right"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6">Chưa có nhật ký phù hợp.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links() }}
@endsection
