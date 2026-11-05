@extends('layouts.portal')
{{-- Before/after đã được sanitizer lọc; Blade escape JSON và đánh dấu trường khác nhau, không sửa log gốc. --}}
@section('heading', 'Chi tiết nhật ký #'.$log->id)
@section('actions')
    <a class="btn btn-outline-primary" href="{{ route('audit.index', ['subject_type' => class_basename($log->subject_type), 'subject_id' => $log->subject_id]) }}"><i data-lucide="clock-3"></i> Cùng đối tượng</a>
@endsection
@section('content')
    <section class="work-section">
        <dl class="detail-grid">
            <dt>Sự kiện</dt><dd class="audit-event-name">{{ $log->event }}</dd>
            <dt>Thời gian</dt><dd>{{ $log->created_at->format('d/m/Y H:i:s') }}</dd>
            <dt>Người thao tác</dt><dd>{{ $log->actor?->name ?? 'Hệ thống' }}</dd>
            <dt>Đối tượng</dt><dd>{{ \App\Services\AuditSubject::LABELS[class_basename($log->subject_type)] ?? class_basename($log->subject_type) }} #{{ $log->subject_id }}</dd>
            <dt>Kết quả</dt><dd>Thành công</dd>
            <dt>Mã request</dt><dd class="audit-event-name"><a href="{{ route('audit.index', ['request_id' => $log->request_id]) }}">{{ $log->request_id }}</a></dd>
            <dt>Lý do</dt><dd>{{ $reason ?: 'Không ghi lý do riêng' }}</dd>
        </dl>
        @if ($sourceUrl)
            <a class="btn btn-primary mt-3" href="{{ $sourceUrl }}"><i data-lucide="arrow-up-right"></i> Mở hồ sơ liên quan</a>
        @endif
    </section>
    <section class="work-section">
        <h2 class="mb-3">Thay đổi dữ liệu</h2>
        <div class="table-responsive">
            <table class="table work-table audit-changes">
                <thead><tr><th>Trường</th><th>Trước</th><th>Sau</th></tr></thead>
                <tbody>
                    @forelse (array_unique(array_merge(array_keys($before), array_keys($after))) as $field)
                        <tr @class(['audit-changed' => ($before[$field] ?? null) !== ($after[$field] ?? null)])>
                            <th>{{ $field }}</th>
                            @foreach ([$before, $after] as $values)
                                <td>
                                    @if (array_key_exists($field, $values))
                                        <pre>{{ json_encode($values[$field], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @else
                                        <span class="text-muted">Chưa ghi nhận</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="3">Sự kiện không có thay đổi trường dữ liệu.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
