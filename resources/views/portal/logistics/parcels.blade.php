{{-- Người giữ thực tế và custody_version của từng kiện là căn cứ bàn giao, khác người được phân công task. --}}
<div class="table-responsive">
    <table class="table align-middle">
        <thead>
            <tr>
                <th>Kiện</th>
                <th>Người giữ hiện tại</th>
                <th>Vị trí</th>
                <th>Cân thực tế</th>
                <th>Tình trạng</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($order->parcels as $item)
                <tr>
                    <td>#{{ $item->parcel_number }}</td>
                    <td>{{ match ($item->holder_type) {'warehouse' => $item->warehouse?->name ?? 'Kho','transfer' => 'Đang chuyển kho','recipient' => 'Đã bàn giao người nhận','shop' => 'Đã hoàn về shop','lost' => 'Đã xác nhận mất',default => $item->holder?->name ?? 'Chưa xác định'} }}
                    </td>
                    <td>{{ $item->location?->code ?? '—' }}</td>
                    <td>{{ $item->actual_weight_grams ? number_format($item->actual_weight_grams) . ' g' : 'Chưa cân' }}
                    </td>
                    <td>{{ match ($item->condition) {'damaged' => 'Hư hỏng', 'lost' => 'Mất kiện', default => 'Nguyên vẹn'} }}</td>
                </tr>
            @empty<tr>
                    <td colspan="5" class="text-secondary py-3">Chưa lấy kiện từ shop.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
