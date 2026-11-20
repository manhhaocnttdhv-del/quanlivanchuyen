@props(['href', 'icon', 'active' => false])
<a href="{{ $href }}" @class(['portal-nav-link', 'active' => $active]) @if ($active) aria-current="page" @endif>
    <i data-lucide="{{ $icon }}"></i><span>{{ $slot }}</span>
</a>
