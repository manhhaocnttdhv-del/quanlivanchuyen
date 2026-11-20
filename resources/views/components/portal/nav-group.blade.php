@props(['label', 'icon', 'active' => false, 'open' => false])
<details @class(['portal-nav-group', 'has-current' => $active]) @if ($open || $active) open @endif>
    <summary><i data-lucide="{{ $icon }}"></i><span>{{ $label }}</span><i class="portal-nav-chevron" data-lucide="chevron-down"></i></summary>
    <div class="portal-nav-items">{{ $slot }}</div>
</details>
