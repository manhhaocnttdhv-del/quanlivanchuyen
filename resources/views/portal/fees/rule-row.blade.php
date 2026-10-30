{{-- Cùng cấu trúc cho dòng đã lưu và template thêm mới; mỗi lỗi gắn đúng chỉ số rules sau validation. --}}
@php
    $rowLabel = is_int($i) ? $i + 1 : 'mới';
    $fields = [
        'min_weight_grams' => ['Từ (g)', 1, 20000, 1],
        'max_weight_grams' => ['Đến (g)', 1, 20000, 20000],
        'base_weight_grams' => ['Cân cơ bản (g)', 1, 20000, 500],
        'step_weight_grams' => ['Bước cân (g)', 1, 20000, 500],
        'base_fee' => ['Phí cơ bản (đ)', 0, 10000000, 0],
        'step_fee' => ['Phí mỗi bước (đ)', 0, 10000000, 0],
        'max_cod' => ['COD tối đa (đ)', 0, 100000000, 20000000],
        'return_fee' => ['Phí hoàn (đ)', 0, 10000000, 0],
    ];
@endphp
<tr>
    @foreach (['route' => ['Tuyến', config('shipping.routes')], 'service' => ['Dịch vụ', config('shipping.services')]] as $field => [$label, $options])
        @php
            $errorName = 'rules.'.$i.'.'.$field;
        @endphp
        <td>
            <select class="form-select @error($errorName) is-invalid @enderror" name="rules[{{ $i }}][{{ $field }}]"
                aria-label="{{ $label }} dòng {{ $rowLabel }}" @error($errorName) aria-invalid="true" @enderror
                aria-describedby="fee-rule-{{ $i }}-{{ $field }}-error">
                @foreach ($options as $key => $option)
                    <option value="{{ $key }}" @selected(($rule[$field] ?? '') === $key)>{{ $option['label'] }}</option>
                @endforeach
            </select>
            <div class="invalid-feedback" id="fee-rule-{{ $i }}-{{ $field }}-error">{{ $errors->first($errorName) }}</div>
        </td>
    @endforeach
    @foreach ($fields as $field => [$label, $min, $max, $default])
        @php
            $errorName = 'rules.'.$i.'.'.$field;
            $fieldValue = $rule[$field] ?? $default;
        @endphp
        <td>
            <input class="form-control @error($errorName) is-invalid @enderror" type="number" inputmode="numeric"
                name="rules[{{ $i }}][{{ $field }}]" value="{{ is_scalar($fieldValue) ? $fieldValue : '' }}"
                aria-label="{{ $label }} dòng {{ $rowLabel }}" min="{{ $min }}" max="{{ $max }}" step="1" required
                @error($errorName) aria-invalid="true" @enderror aria-describedby="fee-rule-{{ $i }}-{{ $field }}-error">
            <div class="invalid-feedback" id="fee-rule-{{ $i }}-{{ $field }}-error">{{ $errors->first($errorName) }}</div>
        </td>
    @endforeach
    <td><button class="btn btn-outline-danger remove-fee-rule" type="button" aria-label="Xóa bậc cân" title="Xóa bậc cân"><i data-lucide="trash-2"></i></button></td>
</tr>
