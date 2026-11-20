@extends('layouts.portal')
@section('heading', 'Chuyển kho')
@section('content')
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Phiếu / vận đơn</th><th>Kho gửi</th><th>Kho nhận</th><th>Đã nhận</th><th>Trạng thái</th></tr></thead>
            <tbody>
                @forelse ($transfers as $transfer)
                    <tr>
                        <td><a href="{{ route('transfers.show', $transfer) }}">{{ $transfer->code }}</a><br><small>{{ $transfer->order->tracking_code }}</small></td>
                        <td>{{ $transfer->source->name }}</td><td>{{ $transfer->destination->name }}</td>
                        <td>{{ $transfer->received_count }} / {{ $transfer->lines_count }} kiện</td>
                        <td><span class="status-badge">{{ \App\Models\WarehouseTransfer::STATUSES[$transfer->status] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-secondary py-4">Chưa có chuyến chuyển kho trong phạm vi tài khoản.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $transfers->links() }}
@endsection
