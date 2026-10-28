@extends('layouts.portal')
@section('heading', $version ? 'Biểu phí: ' . $version->name : 'Tạo phiên bản biểu phí')
@section('content')
    @php($published = $version && $version->status === 'published')
    @if ($published)
        <div class="alert alert-info">
            Đã công bố. Các mức phí được giữ nguyên để bảo toàn lịch sử vận đơn.</div>
    @endif
    <form method="post" action="{{ $version ? route('fees.update', $version) : route('fees.store') }}">@csrf @if ($version)
            @method('PUT')
        @endif
        <fieldset @disabled($published)>
            <div class="row g-3">
                <div class="col-lg-4"><x-field name="name" label="Tên phiên bản" :value="$version?->name" required /></div>
                <div class="col-lg-4"><x-field type="datetime-local" name="active_from" label="Hiệu lực từ" :value="$version?->active_from->format('Y-m-d\TH:i')"
                        required /></div>
                <div class="col-lg-4"><x-field type="datetime-local" name="active_until"
                        label="Đến trước thời điểm (để trống nếu không giới hạn)" :value="$version?->active_until?->format('Y-m-d\TH:i')" /></div>
            </div>
            <p class="small text-secondary mt-3">Mỗi cặp tuyến/dịch vụ phủ 1–20.000g. Tiền nhập số nguyên đồng. Muốn chia
                bậc cân, thêm dòng và chỉnh các khoảng liên tục.</p>
            <div class="table-responsive">
                <table class="table work-table fee-editor">
                    <thead>
                        <tr>
                            <th>Tuyến</th>
                            <th>Dịch vụ</th>
                            <th>Từ g</th>
                            <th>Đến g</th>
                            <th>Cân cơ bản g</th>
                            <th>Bước g</th>
                            <th>Phí cơ bản đ</th>
                            <th>Phí mỗi bước đ</th>
                            <th>COD tối đa đ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="fee-rule-rows">
                        @foreach (collect((array) old('rules', $rules))->filter(fn ($rule) => is_array($rule))->values() as $i => $rule)
                            <tr>
                                <td><select class="form-select" name="rules[{{ $i }}][route]"
                                        aria-label="Tuyến dòng {{ $i + 1 }}">
                                        @foreach (config('shipping.routes') as $key => $option)
                                            <option value="{{ $key }}" @selected(($rule['route'] ?? '') === $key)>
                                                {{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><select class="form-select" name="rules[{{ $i }}][service]"
                                        aria-label="Dịch vụ dòng {{ $i + 1 }}">
                                        @foreach (config('shipping.services') as $key => $option)
                                            <option value="{{ $key }}" @selected(($rule['service'] ?? '') === $key)>
                                                {{ $option['label'] }}</option>
                                        @endforeach
                                    </select></td>
                                @foreach (['min_weight_grams', 'max_weight_grams', 'base_weight_grams', 'step_weight_grams', 'base_fee', 'step_fee', 'max_cod'] as $field)
                                    <td><input class="form-control" type="number"
                                            name="rules[{{ $i }}][{{ $field }}]"
                                            value="{{ (int) ($rule[$field] ?? 0) }}"
                                            aria-label="{{ $field }} dòng {{ $i + 1 }}" min="0"
                                            step="1"></td>
                                @endforeach
                                <td><button class="btn btn-outline-danger remove-fee-rule" type="button"
                                        aria-label="Xóa bậc cân"><i data-lucide="trash-2"></i></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (!$published)
                <button type="button" class="btn btn-outline-primary mt-2" id="add-fee-rule"><i data-lucide="plus"></i>
                    Thêm bậc cân</button>
                <div class="form-actions"><button class="btn btn-primary"><i data-lucide="save"></i> Lưu bản nháp</button>
                </div>
            @endif
        </fieldset>
    </form>
    @if ($version && !$published)
        <section class="work-section">
            <h2>Công bố biểu phí</h2>
            <form method="post" action="{{ route('fees.publish', $version) }}">@csrf<x-field name="reason"
                    label="Căn cứ công bố" required /><button class="btn btn-outline-primary mt-3">Công bố phiên bản đã
                    lưu</button></form>
        </section>
    @endif
@endsection
