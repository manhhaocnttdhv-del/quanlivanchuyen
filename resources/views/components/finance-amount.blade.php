@props(['group', 'source', 'label', 'value' => 0, 'max' => 9999999999999])
@php($errorName = $group.'.'.$source)
<div class="finance-amount">
    <label class="visually-hidden" for="{{ $group }}-{{ $source }}">{{ $label }}</label>
    <input class="form-control text-end @error($errorName) is-invalid @enderror" type="number" inputmode="numeric"
        id="{{ $group }}-{{ $source }}" name="{{ $group }}[{{ $source }}]" min="0" max="{{ $max }}" step="1"
        value="{{ old($errorName, $value) }}" aria-describedby="{{ $group }}-{{ $source }}-error">
    @error($errorName)<div class="invalid-feedback" id="{{ $group }}-{{ $source }}-error">{{ $message }}</div>@enderror
</div>
