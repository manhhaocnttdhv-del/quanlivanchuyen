@extends('layouts.portal')
@section('heading', 'Phiên bản biểu phí')
@section('actions')<a class="btn btn-primary" href="{{ route('fees.create') }}">
    <i data-lucide="plus"></i> Tạo phiên bản</a>@endsection
@section('content')
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Phiên bản</th>
                    <th>Từ ngày</th>
                    <th>Đến trước ngày</th>
                    <th>Quy tắc</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($versions as $version)
                    <tr>
                        <td>{{ $version->name }}</td>
                        <td>{{ $version->active_from->format('d/m/Y H:i') }}</td>
                        <td>{{ $version->active_until?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</td>
                        <td>{{ $version->rules_count }}</td>
                        <td>{{ $version->status === 'draft' ? 'Bản nháp' : 'Đã công bố' }}</td>
                        <td><a class="btn btn-outline-primary" href="{{ route('fees.edit', $version) }}">Xem / sửa</a></td>
                </tr>@empty<tr>
                        <td colspan="6">Chưa có biểu phí.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>{{ $versions->links() }}
@endsection
