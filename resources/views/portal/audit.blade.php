@extends('layouts.portal')
@section('heading', 'Nhật ký thay đổi')
@section('content')
    <form class="filter-bar" method="get">
        <x-field name="q" label="Sự kiện hoặc ID đối tượng" :value="request('q')" /><button
            class="btn btn-outline-primary">Tìm</button>
    </form>
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Người thao tác</th>
                    <th>Sự kiện / đối tượng</th>
                    <th>Thay đổi</th>
                    <th>Lý do</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $log->actor?->name ?? 'Hệ thống' }}</td>
                        <td>{{ $log->event }}<small>{{ class_basename($log->subject_type) }}
                                #{{ $log->subject_id }}</small></td>
                        <td>
                            <details>
                                <summary>Xem trước / sau</summary>
                                <pre>{{ json_encode(['before' => $log->old_values, 'after' => $log->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        </td>
                        <td>{{ $log->reason }}</td>
                </tr>@empty<tr>
                        <td colspan="5">Chưa có nhật ký phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>{{ $logs->links() }}
@endsection
