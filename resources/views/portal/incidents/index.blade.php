@extends('layouts.portal')
@section('heading', 'Sự cố vận chuyển')
@section('actions')<a class="btn btn-primary" href="{{ route('incidents.create') }}">
    <i data-lucide="plus"></i> Mở sự cố</a>@endsection
@section('content')
    <form method="get" class="filter-bar">
        <x-field name="q" label="Mã sự cố / vận đơn" :value="$filters['q'] ?? ''" maxlength="60" />
        <x-field name="status" label="Trạng thái" :value="$filters['status'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\Incident::STATUSES" />
        <x-field name="type" label="Loại sự cố" :value="$filters['type'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\Incident::TYPES" />
        <x-field name="severity" label="Mức độ" :value="$filters['severity'] ?? ''" :options="['' => 'Tất cả'] + \App\Models\Incident::SEVERITIES" />
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mine" value="1"
                @checked($filters['mine'] ?? false)> Tôi phụ trách</label>
        <button class="btn btn-primary"><i data-lucide="search"></i> Lọc</button><a class="btn btn-outline-primary"
            href="{{ route('incidents.index') }}">Bỏ lọc</a>
    </form>
    <div class="table-responsive">
        <table class="table work-table">
            <thead>
                <tr>
                    <th>Hồ sơ / vận đơn</th>
                    <th>Nội dung</th>
                    <th>Mức độ</th>
                    <th>Trạng thái / khóa</th>
                    <th>Phụ trách</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($incidents as $incident)
                    <tr>
                        <td><a class="logistics-code"
                                href="{{ route('incidents.show', $incident) }}">{{ $incident->code }}</a><small>{{ $incident->order->tracking_code }}</small><small>{{ $incident->warehouse->name }}</small>
                        </td>
                        <td><strong>{{ $incident->title }}</strong><small>{{ \App\Models\Incident::TYPES[$incident->type] }}</small>
                        </td>
                        <td><span
                                class="case-priority priority-{{ $incident->severity }}">{{ \App\Models\Incident::SEVERITIES[$incident->severity] }}</span>
                        </td>
                        <td><span class="status-badge">{{ \App\Models\Incident::STATUSES[$incident->status] }}</span>
                            @if ($incident->blocks_dispatch)
                                <small class="text-danger">Khóa giao / hoàn</small>
                                @endif @if ($incident->blocks_reconciliation)
                                    <small class="text-danger">Khóa đối soát</small>
                                    @endif @if ($incident->financial_status === 'pending_finance')
                                        <small>Chờ xử lý tài chính</small>
                                    @endif
                        </td>
                        <td>{{ $incident->assignee?->name ?? 'Chưa phân công' }}</td>
                    </tr>
                @empty<tr>
                        <td colspan="5" class="text-secondary text-center py-5">Chưa có sự cố phù hợp.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $incidents->links() }}
@endsection
