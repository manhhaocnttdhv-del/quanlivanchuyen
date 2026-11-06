@extends('layouts.portal')
@section('heading', 'Phiếu chuyển kho')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('transfers.index') }}"><i data-lucide="arrow-left"></i> Chuyển kho</a>@endsection
@section('content')
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $transfer->code }}</strong><p>{{ $transfer->order->tracking_code }}</p></div>
        <span class="status-badge">{{ \App\Models\WarehouseTransfer::STATUSES[$transfer->status] }}</span>
    </div>
    <dl class="detail-grid">
        <dt>Kho gửi</dt><dd>{{ $transfer->source->name }}</dd>
        <dt>Kho nhận</dt><dd>{{ $transfer->destination->name }}</dd>
        <dt>Chặng</dt><dd>{{ $transfer->purpose === 'return' ? 'Chuyển về kho gửi để hoàn shop' : 'Chuyển đến khu vực người nhận' }}</dd>
        <dt>Vận chuyển</dt><dd>{{ $transfer->carrier }} · {{ $transfer->transport_reference }}</dd>
        <dt>Xuất chuyển</dt><dd>{{ $transfer->creator->name }} · {{ $transfer->dispatched_at->format('H:i d/m/Y') }}</dd>
        <dt>Căn cứ</dt><dd>{{ $transfer->reason }}</dd>
    </dl>
    <section class="work-section">
        <h2>Kiện chuyển kho</h2>
        <div class="table-responsive"><table class="table align-middle">
            <thead><tr><th>Kiện</th><th>Trạng thái nhận</th><th>Người nhận / vị trí</th><th>Bằng chứng</th></tr></thead>
            <tbody>@foreach ($transfer->lines as $line)
                <tr><td>#{{ $line->item->parcel_number }}</td>
                    <td>{{ $line->condition === 'lost' ? 'Đã xác nhận mất' : ($line->received_at ? ($line->condition === 'damaged' ? 'Đã nhận · Hư hỏng' : 'Đã nhận') : 'Chưa nhận') }}</td>
                    <td>{{ $line->receiver?->name ?? '—' }}<br><small>{{ $line->location?->name }} {{ $line->received_at?->format('H:i d/m/Y') }}</small></td>
                    <td>@if ($line->evidence_path)<a href="{{ route('transfers.evidence', [$transfer, $line]) }}" target="_blank" rel="noopener">Xem ảnh</a>@else — @endif</td>
                </tr>
            @endforeach</tbody>
        </table></div>
    </section>
    @if (in_array($transfer->status, ['in_transit', 'partial']) && $policy->receive(auth()->user(), $transfer))
        <section class="work-section">
            <h2>Kho nhận kiểm đếm</h2>
            <form method="post" enctype="multipart/form-data" action="{{ route('transfers.receive', $transfer) }}" data-confirm="Xác nhận các kiện thực tế đã nhận?">
                @csrf<input type="hidden" name="revision" value="{{ $transfer->order->revision }}">
                <div class="table-responsive"><table class="table align-middle">
                    <thead><tr><th>Kiện</th><th>Thực nhận</th><th>Tình trạng</th></tr></thead>
                    <tbody>@foreach ($transfer->lines->whereNull('received_at')->where('condition', '!=', 'lost') as $line)
                        <tr><td>#{{ $line->item->parcel_number }}</td>
                            <td><input type="hidden" name="lines[{{ $line->id }}][received]" value="0">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="lines[{{ $line->id }}][received]" value="1" @checked(old('lines.'.$line->id.'.received', false))> Đã nhận</label>
                                @error('lines.'.$line->id.'.received')<span class="text-danger small">{{ $message }}</span>@enderror
                            </td>
                            <td><x-field name="lines[{{ $line->id }}][condition]" id="condition-{{ $line->id }}" label="Tình trạng kiện #{{ $line->item->parcel_number }}" :value="old('lines.'.$line->id.'.condition', 'good')" :options="['good' => 'Nguyên vẹn', 'damaged' => 'Hư hỏng']" required />
                                @error('lines.'.$line->id.'.condition')<span class="text-danger small">{{ $message }}</span>@enderror
                            </td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6"><x-field name="location_id" label="Vị trí nhập kho" :options="$locations" placeholder="Chọn vị trí kho nhận" required /></div>
                    <div class="col-md-6"><x-field name="evidence" label="Ảnh kiểm đếm (bắt buộc khi thiếu / hỏng)" type="file" accept="image/jpeg,image/png,image/webp" /></div>
                    <div class="col-12"><x-field name="reason" label="Biên bản kiểm đếm / căn cứ" type="textarea" minlength="5" maxlength="1000" required /></div>
                </div>
                <button class="btn btn-primary mt-3" @disabled($locations->isEmpty())><i data-lucide="package-check"></i> Xác nhận thực nhận</button>
            </form>
        </section>
    @elseif (in_array($transfer->status, ['in_transit', 'partial']))
        <p class="alert alert-info">Chờ tài khoản kho nhận kiểm đếm. Người xuất chuyển không tự xác nhận nhận hàng.</p>
    @endif
    @if (app(\App\Policies\LogisticsPolicy::class)->dispatch(auth()->user(), $transfer->order->warehouse_id) || app(\App\Policies\LogisticsPolicy::class)->warehouse(auth()->user(), $transfer->order->warehouse_id))
        <a class="btn btn-outline-primary mt-3" href="{{ route('logistics.order', $transfer->order) }}"><i data-lucide="clipboard-list"></i> Mở vận hành vận đơn</a>
    @endif
@endsection
