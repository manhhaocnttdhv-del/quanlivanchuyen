{{-- Giá trị old() chỉ nhận scalar để lỗi input dạng mảng không làm vỡ Blade; nội dung luôn được escape. --}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => '',
    'options' => null,
    'placeholder' => null,
    'hint' => null,
    'id' => null,
])
@php
    $fieldId = $id ?? $name;
    $oldValue = old($name, $value);
    $fieldValue = is_scalar($oldValue) ? $oldValue : '';
    $error = $errors->first($name);
    $controlClass = ($options === null ? 'form-control' : 'form-select') . ($error ? ' is-invalid' : '');
@endphp
<div class="field-group">
    <label class="form-label" for="{{ $fieldId }}">{{ $label }}@if ($attributes->has('required'))
            <span class="required-mark" aria-hidden="true">*</span>
        @endif
    </label>
    @if ($options !== null)
        <select id="{{ $fieldId }}" name="{{ $name }}" {{ $attributes->class([$controlClass]) }}
            @if ($error) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-feedback">
            @if ($placeholder)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options as $key => $option)
                <option value="{{ $key }}" @selected((string) $fieldValue === (string) $key)>{{ $option }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $name }}" {{ $attributes->class([$controlClass]) }}
            placeholder="{{ $placeholder }}" @if ($error) aria-invalid="true" @endif
            aria-describedby="{{ $fieldId }}-feedback">{{ $fieldValue }}</textarea>
    @else
        <input type="{{ $type }}" id="{{ $fieldId }}" name="{{ $name }}"
            value="{{ $fieldValue }}" {{ $attributes->class([$controlClass]) }} placeholder="{{ $placeholder }}"
            @if ($error) aria-invalid="true" @endif
            aria-describedby="{{ $fieldId }}-feedback">
    @endif
    <div id="{{ $fieldId }}-feedback" class="invalid-feedback" data-error-for="{{ $name }}">
        {{ $error }}</div>
    @if ($hint)
        <div class="form-text">{{ $hint }}</div>
    @endif
</div>
