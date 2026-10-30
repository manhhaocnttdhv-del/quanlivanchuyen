@extends('layouts.portal')
{{-- Danh sách và số quá hạn đã giới hạn scope tại Controller; quyền CSKH quyết định bộ lọc phụ trách. --}}
@section('heading', 'Khiếu nại')
@section('actions')@if (auth()->user()->shop)
    <a class="btn btn-primary" href="{{ route('complaints.create') }}">
        <i data-lucide="plus"></i> Gửi khiếu nại</a>
@endif @endsection
@section('content')
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-4">
        <p class="mb-0 text-secondary">Hồ sơ cần phản hồi và giải quyết</p><a
            class="{{ $overdueCount ? 'text-danger' : 'text-secondary' }}"
            href="{{ route('complaints.index', ['overdue' => 1]) }}"><i data-lucide="clock-3"></i> {{ $overdueCount }} hồ sơ
            quá hạn</a>
    </div>
    <form method="get" class="filter-bar">
        <x-field name="q" label="Mã khiếu nại / vận đơn" :value="$filters['q'] ?? ''" maxlength="60" />
        <x-field name="status" label="Trạng thái" :value="$filters['status'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\Complaint::STATUSES" />
        <x-field name="type" label="Nội dung" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\Complaint::TYPES" />
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="overdue" value="1"
                @checked($filters['overdue'] ?? false)> Quá hạn</label>
        @if (auth()->user()->hasPermission('complaints.manage'))
            <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mine" value="1"
                    @checked($filters['mine'] ?? false)> Tôi phụ trách</label>
        @endif
        <button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button><a class="btn btn-outline-primary"
            href="{{ route('complaints.index') }}">Bỏ lọc</a>
    </form>
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Hồ sơ / vận đơn</th>
                    <th>Nội dung</th>
                    <th>Trạng thái</th>
                    <th>Hạn xử lý</th>
                    <th>Phụ trách</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($complaints as $complaint)
                    <tr>
                        <td><a class="logistics-code"
                                href="{{ route('complaints.show', $complaint) }}">{{ $complaint->code }}</a><small>{{ $complaint->order->tracking_code }}</small>
                        </td>
                        <td><strong>{{ $complaint->title }}</strong><small>{{ \App\Models\Complaint::TYPES[$complaint->type] }}</small>
                        </td>
                        <td><span class="status-badge">{{ \App\Models\Complaint::STATUSES[$complaint->status] }}</span>
                        </td>
                        <td><span
                                class="{{ $complaint->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $complaint->due_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</span>
                            @if ($complaint->isOverdue())
                                <small class="text-danger">Quá hạn</small>
                            @endif
                        </td>
                        <td>{{ $complaint->assignee?->name ?? 'Chờ tiếp nhận' }}</td>
                    </tr>
                @empty<tr>
                        <td colspan="5" class="text-secondary text-center py-5">Chưa có khiếu nại phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $complaints->links() }}
@endsection
