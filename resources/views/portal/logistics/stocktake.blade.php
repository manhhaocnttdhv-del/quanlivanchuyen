@extends('layouts.portal')
@section('heading', 'Biên bản kiểm kê')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('stocktakes.index') }}">
        <i data-lucide="arrow-left"></i> Kiểm kê</a><button class="btn btn-outline-primary" data-print><i
        data-lucide="printer"></i> In biên bản</button>@endsection
@section('content')
    @php($canCount = $stocktake->status === 'draft' && $stocktake->created_by === auth()->id() && $policy->warehouse(auth()->user(), $stocktake->warehouse_id))
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $stocktake->code }}</strong>
            <p>{{ $stocktake->warehouse->name }} / {{ $stocktake->location?->code ?? 'Toàn kho' }} ·
                {{ $stocktake->snapshot_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</p>
        </div><span
            class="status-badge">{{ ['draft' => 'Đang kiểm đếm', 'submitted' => 'Chờ xác nhận', 'approved' => 'Đã xác nhận'][$stocktake->status] }}</span>
    </div>
    <p>{{ $stocktake->creator->name }} · {{ $stocktake->reason }}</p>
    <form method="post" action="{{ route('stocktakes.submit', $stocktake) }}">@csrf
        @foreach ($lines as $line)
            <div class="stocktake-check-row">
                <div><strong class="logistics-code">{{ $line->item->order->tracking_code }}</strong><small
                        class="d-block text-secondary">Kiện #{{ $line->item->parcel_number }} ·
                        {{ $line->location_snapshot ?? 'Chưa có vị trí' }}</small></div>
                @if ($canCount)
                    @php($actual = old('lines.' . $line->id . '.actual_present', $line->actual_present))
                    <div><label class="form-label" for="actual-{{ $line->id }}">Thực tế</label>
                        <select class="form-select" id="actual-{{ $line->id }}"
                            name="lines[{{ $line->id }}][actual_present]" required>
                            <option value="">Chọn kết quả</option>
                            <option value="1" @selected(in_array($actual, [true, 1, '1'], true))>Có kiện</option>
                            <option value="0" @selected(in_array($actual, [false, 0, '0'], true))>Không tìm thấy</option>
                        </select>
                    </div>
                    <div><label class="form-label" for="note-{{ $line->id }}">Ghi chú</label><input
                            class="form-control" id="note-{{ $line->id }}" name="lines[{{ $line->id }}][note]"
                            maxlength="1000"
                            value="{{ is_scalar(old('lines.' . $line->id . '.note', $line->note)) ? old('lines.' . $line->id . '.note', $line->note) : '' }}">
                        @error('lines.' . $line->id . '.note')
                            <p class="text-danger small">{{ $message }}</p>
                        @enderror
                    </div>
                @else<span>{{ $line->actual_present === null ? 'Chưa đếm' : ($line->actual_present ? 'Có kiện' : 'Không tìm thấy') }}
                        @if ($line->movement_detected)
                            <small class="d-block text-warning-emphasis">Biến động sau snapshot</small>
                        @endif
                    </span><span>{{ $line->note }}</span>
                @endif
            </div>
        @endforeach
        @if ($lines->isEmpty())
            <p class="text-secondary my-4">Snapshot không có kiện tại vị trí đã chọn.</p>
        @endif
        @if ($canCount)
            <section class="work-section narrow-form">
                <p class="small text-secondary">Còn {{ $uncounted }} / {{ $lines->total() }} kiện chưa đếm.</p><x-field
                    name="unexpected_note" type="textarea" label="Kiện ngoài danh sách / ghi nhận khác" :value="$stocktake->unexpected_note"
                    maxlength="1000" /><button class="btn btn-outline-primary mt-3" name="finalize" value="0"><i
                        data-lucide="save"></i> Lưu trang này</button><button class="btn btn-primary mt-3" name="finalize"
                    value="1"><i data-lucide="send"></i> Gửi toàn bộ kiểm đếm</button>
        </section>@else<p class="mt-3">{{ $stocktake->unexpected_note }}</p>
        @endif
    </form>
    {{ $lines->links() }}
    @if (
        $stocktake->status === 'submitted' &&
            $stocktake->created_by !== auth()->id() &&
            $policy->approve(auth()->user(), $stocktake->warehouse_id))
        <section class="work-section narrow-form">
            <h2>Xác nhận biên bản</h2>
            <form class="mt-3" method="post" action="{{ route('stocktakes.approve', $stocktake) }}">@csrf<x-field
                    name="reason" label="Kết luận xác nhận" minlength="5" maxlength="1000" required /><button
                    class="btn btn-primary mt-3"><i data-lucide="shield-check"></i> Xác nhận kiểm kê</button></form>
        </section>
    @endif
    @if ($stocktake->approved_at)
        <p>Người xác nhận: {{ $stocktake->approver->name }} · {{ $stocktake->approval_reason }}</p>
        <section class="work-section">
            <h2>Chênh lệch đã lập hồ sơ</h2>@include('portal.logistics.discrepancies', ['discrepancies' => $stocktake->discrepancies])
        </section>
    @endif
@endsection
