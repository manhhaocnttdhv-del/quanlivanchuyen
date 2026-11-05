{{-- Partial dùng route/operation do view cha chỉ định; CSRF và revision đi kèm mọi thao tác trạng thái tài chính. --}}
<form method="post" action="{{ route($routeName, [$document, $operation]) }}" class="finance-action" data-confirm="{{ $label }}?">
    @csrf
    <input type="hidden" name="revision" value="{{ $document->revision }}">
    <x-field name="reason" :id="'reason-'.$operation" :label="'Lý do '.$label" type="textarea" rows="2" minlength="5" maxlength="2000" required />
    <button class="btn {{ in_array($operation, ['reject', 'cancel']) ? 'btn-outline-danger' : 'btn-primary' }} mt-3"><i data-lucide="{{ $icon ?? 'circle-check' }}"></i> {{ $label }}</button>
</form>
