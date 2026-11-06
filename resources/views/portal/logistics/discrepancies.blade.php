{{-- Sai lệch và khóa theo từng hồ sơ; Service xử lý chỉ gỡ khóa tương ứng, không tự thay cước/tồn. --}}
@forelse ($discrepancies as $difference)
    <article class="discrepancy-row">
        <div class="d-flex flex-wrap justify-content-between gap-2"><strong>#{{ $difference->id }} ·
                {{ \App\Models\Discrepancy::TYPES[$difference->type] ?? $difference->type }} @if ($difference->item)
                    · Kiện #{{ $difference->item->parcel_number }}
                @endif
            </strong><span
                class="status-badge {{ $difference->status === 'open' && $difference->blocks_dispatch ? 'bg-danger-subtle text-danger' : '' }}">{{ $difference->status === 'resolved' ? 'Đã xử lý' : ($difference->blocks_dispatch ? 'Đang giữ / chặn xuất' : 'Chờ xác minh') }}</span>
        </div>
        <p class="small mt-2 mb-1">{{ $difference->reason }}</p><small
            class="text-secondary">{{ $difference->creator->name }} ·
            {{ $difference->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }} ·
            {{ $difference->severity === 'high' ? 'Mức độ cao' : 'Mức độ vừa' }}</small>
        @if ($difference->order)
            @if ($policy->dispatch(auth()->user(), $difference->order->warehouse_id) || $policy->warehouse(auth()->user(), $difference->order->warehouse_id))
                <a class="d-block small mt-2 logistics-code"
                    href="{{ route('logistics.order', $difference->order) }}">{{ $difference->order->tracking_code }}</a>
            @else
                <span class="d-block small mt-2 logistics-code">{{ $difference->order->tracking_code }}</span>
            @endif
            @if ($difference->status === 'open' && app(\App\Policies\IncidentPolicy::class)->createForOrder(auth()->user(), $difference->order, $difference->id))
                <a class="btn btn-outline-primary btn-sm mt-2" href="{{ route('incidents.create', ['order_id' => $difference->order_id, 'discrepancy_id' => $difference->id]) }}"><i data-lucide="triangle-alert"></i> Mở sự cố</a>
            @endif
        @endif
        @if ($difference->evidence_path)
            <a class="d-block small mt-2" target="_blank"
                href="{{ route('logistics.evidence', ['kind' => 'discrepancy', 'id' => $difference->id]) }}">Ảnh bằng
                chứng</a>
        @endif
        @if (
            $difference->status === 'open' &&
                $difference->type === 'weight' &&
                $policy->warehouse(auth()->user(), $difference->warehouse_id))
            <details class="mt-2">
                <summary>Đề nghị tính lại cước theo cân thực tế</summary>
                <form class="narrow-form mt-3" method="post"
                    action="{{ route('logistics.weight-review', $difference) }}">@csrf<x-field name="reason"
                        id="reweigh-{{ $difference->id }}" label="Căn cứ đề nghị tính lại cước" minlength="5"
                        maxlength="1000" required /><button class="btn btn-outline-primary mt-2"><i
                            data-lucide="send"></i> Gửi đề nghị</button></form>
            </details>
        @endif
        @if ($difference->status === 'resolved')
            <p class="small mt-2">{{ $difference->resolver?->name }}: {{ $difference->resolution_reason }}</p>
        @elseif ($policy->approve(auth()->user(), $difference->warehouse_id) && $difference->created_by !== auth()->id())
            <details class="mt-2 print-hidden">
                <summary>Xử lý sai lệch / bỏ giữ</summary>
                <form method="post" class="narrow-form mt-3" action="{{ route('logistics.resolve', $difference) }}"
                    data-confirm="Xác nhận xử lý sai lệch này?">@csrf<x-field name="reason"
                        id="resolve-{{ $difference->id }}" label="Kết luận / căn cứ bỏ giữ" minlength="5"
                        maxlength="1000" required /><button class="btn btn-outline-primary mt-2"><i
                            data-lucide="shield-check"></i> Xác nhận xử lý</button></form>
            </details>
        @endif
    </article>
@empty<p class="text-secondary py-3">Chưa ghi nhận sai lệch.</p>
@endforelse
