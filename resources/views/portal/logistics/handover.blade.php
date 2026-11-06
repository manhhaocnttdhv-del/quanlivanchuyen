@extends('layouts.portal')
{{-- Bên nhận khác người lập kiểm từng kiện; xác nhận mới đổi người giữ, không suy ra từ lúc lập phiếu. --}}
@section('heading', $doc->direction === 'inbound' ? 'Phiếu nhập kho' : 'Phiếu xuất kho')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('logistics.show', $doc->task) }}">
        <i data-lucide="arrow-left"></i> Nhiệm vụ</a><button class="btn btn-outline-primary" data-print><i
        data-lucide="printer"></i> In phiếu</button>@endsection
@section('content')
    {{-- Nhập: kho nhận; xuất: shipper nhận. Người lập phiếu không tự xác nhận phía nhận. --}}
    @php($outboundStopped = $doc->direction === 'outbound' && $doc->status === 'pending' && !app(\App\Services\OrderRouting::class)->warehouseActive($doc->order))
    @php($canConfirm = !$outboundStopped && $doc->status === 'pending' && $doc->created_by !== auth()->id() && ($doc->direction === 'inbound' ? $policy->warehouse(auth()->user(), $doc->warehouse_id) : $policy->shipper(auth()->user(), $doc->task)))
    @if ($outboundStopped)<p class="alert alert-warning">Kho hoặc khu vực đã ngừng xuất hàng. Phiếu chưa được giao kiện; liên hệ điều phối thu hồi nhiệm vụ hoặc chờ mở lại kho.</p>@endif
    <div class="order-identity">
        <div><strong class="order-code logistics-code">{{ $doc->code }}</strong>
            <p>{{ $doc->order->tracking_code }} · {{ $doc->lines->count() }} kiện · {{ $doc->shipper->name }}</p>
        </div><span
            class="status-badge">{{ ['pending' => 'Chờ bên nhận kiểm đếm', 'confirmed' => 'Đã xác nhận', 'cancelled' => 'Đã hủy'][$doc->status] }}</span>
    </div>
    <dl class="detail-grid">
        <dt>Người lập</dt>
        <dd>{{ $doc->creator->name }} · {{ $doc->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</dd>
        <dt>Căn cứ</dt>
        <dd>{{ $doc->reason }}</dd>
        @if ($doc->confirmed_at)
            <dt>Người nhận</dt>
            <dd>{{ $doc->confirmer->name }} · {{ $doc->confirmed_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}
            </dd>
            <dt>Ghi nhận</dt>
            <dd>{{ $doc->confirmation_reason }}</dd>
        @endif
    </dl>
    <form method="post" enctype="multipart/form-data" action="{{ route('logistics.confirm', $doc) }}">@csrf
        <div class="parcel-check-list">
            @foreach ($doc->lines as $line)
                <section class="parcel-check-row">
                    <strong>Kiện #{{ $line->item->parcel_number }}</strong>
                    @if ($canConfirm)
                        <div><label class="form-label" for="received-{{ $line->id }}">Thực nhận</label><select
                                id="received-{{ $line->id }}" name="lines[{{ $line->id }}][received]"
                                class="form-select" required>
                                <option value="">Chọn kết quả</option>
                                <option value="1" @selected(old('lines.' . $line->id . '.received') === '1')>Có nhận</option>
                                <option value="0" @selected(old('lines.' . $line->id . '.received') === '0')>Không nhận / thiếu</option>
                            </select>
                            @error('lines.' . $line->id . '.received')
                                <p class="text-danger small">{{ $message }}</p>
                            @enderror
                        </div>
                        <div><label class="form-label" for="condition-{{ $line->id }}">Tình trạng</label><select
                                id="condition-{{ $line->id }}" class="form-select"
                                name="lines[{{ $line->id }}][condition]">
                                <option value="good">Nguyên vẹn</option>
                                <option value="damaged" @selected(old('lines.' . $line->id . '.condition') === 'damaged')>Hư hỏng</option>
                            </select></div>
                        @if ($doc->direction === 'inbound')
                            <div><label class="form-label" for="weight-{{ $line->id }}">Cân thực tế (g)</label><input
                                    class="form-control @error('lines.' . $line->id . '.weight_grams') is-invalid @enderror"
                                    id="weight-{{ $line->id }}" type="number"
                                    name="lines[{{ $line->id }}][weight_grams]"
                                    value="{{ is_scalar(old('lines.' . $line->id . '.weight_grams')) ? old('lines.' . $line->id . '.weight_grams') : '' }}"
                                    min="1" max="20000000">
                                @error('lines.' . $line->id . '.weight_grams')
                                    <p class="text-danger small">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    @else
                        <span>{{ $line->received === null ? 'Chưa kiểm đếm' : ($line->received ? 'Đã nhận' : 'Thiếu / không nhận') }}</span><span>{{ $line->condition === 'damaged' ? 'Hư hỏng' : ($line->condition ? 'Nguyên vẹn' : '—') }}</span><span>{{ $line->actual_weight_grams ? number_format($line->actual_weight_grams) . ' g' : '' }}</span>
                    @endif
                </section>
            @endforeach
        </div>
        @if ($canConfirm)
            <section class="work-section narrow-form print-hidden">
                <h2>Xác nhận phía nhận</h2>
                <div class="row g-3 mt-1">
                    @if ($doc->direction === 'inbound')
                        <div class="col-12"><x-field name="location_id" label="Vị trí nhập" :options="$locations"
                                placeholder="Chọn vị trí kho" required /></div>
                    @endif
                    <div class="col-12"><x-field type="file" name="evidence" label="Ảnh bằng chứng"
                            accept="image/jpeg,image/png,image/webp" /></div>
                    <div class="col-12"><x-field name="reason" label="Kết quả kiểm đếm / căn cứ" minlength="5"
                            maxlength="1000" required /></div>
                </div><button class="btn btn-primary mt-3"><i data-lucide="circle-check"></i> Xác nhận thực nhận</button>
            </section>
        @endif
    </form>
    @if ($doc->evidence_path)
        <a target="_blank" href="{{ route('logistics.evidence', ['kind' => 'handover', 'id' => $doc->id]) }}">Mở ảnh bằng
            chứng</a>
    @endif
@endsection
