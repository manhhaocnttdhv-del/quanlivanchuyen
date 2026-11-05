{{-- Tên group[source_id] khớp validation từng nguồn; max trên UI là gợi ý, Service vẫn khóa và kiểm tra số khả dụng. --}}
@props(['group', 'source', 'label', 'value' => 0, 'max' => 9999999999999])
@php
    $errorName = $group.'.'.$source;
    $oldValue = old($errorName, $value);
    $fieldValue = is_scalar($oldValue) ? $oldValue : '';
@endphp
<div class="finance-amount">
    <label class="visually-hidden" for="{{ $group }}-{{ $source }}">{{ $label }}</label>
    <input class="form-control text-end @error($errorName) is-invalid @enderror" type="number" inputmode="numeric"
        id="{{ $group }}-{{ $source }}" name="{{ $group }}[{{ $source }}]" min="0" max="{{ $max }}" step="1"
        value="{{ $fieldValue }}" @error($errorName) aria-invalid="true" @enderror
        aria-describedby="{{ $group }}-{{ $source }}-error">
    <div class="invalid-feedback" id="{{ $group }}-{{ $source }}-error">{{ $errors->first($errorName) }}</div>
</div>
