<form method="get" class="filter-bar">
    <x-field name="q" label="Tìm mã vận đơn" :value="$filters['q'] ?? ''" maxlength="60" />
    <button class="btn btn-primary"><i data-lucide="search"></i> Tìm</button>
</form>
<div class="table-responsive">
    <table class="table work-table">
        <thead>
            <tr>
                <th>Vận đơn</th>
                <th>Người nhận</th>
                <th>Trạng thái</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $option)
                <tr>
                    <td class="logistics-code">{{ $option->tracking_code }}</td>
                    <td>{{ $option->recipient_name }}</td>
                    <td>{{ \App\Models\Order::STATUSES[$option->status] }}</td>
                    <td><a class="btn btn-outline-primary"
                            href="{{ route($pickerRoute, ['order_id' => $option->id]) }}"><i
                                data-lucide="arrow-right"></i> Chọn đơn</a></td>
                </tr>
            @empty<tr>
                    <td colspan="4" class="text-secondary py-4">Chưa có vận đơn phù hợp trong phạm vi của bạn.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $orders->links() }}
