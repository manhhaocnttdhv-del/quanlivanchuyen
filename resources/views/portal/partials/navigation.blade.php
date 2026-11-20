@php
    $user = auth()->user();
    $warehouseAccess = $user->hasPermission('logistics.warehouse');
    $dispatchAccess = $user->hasPermission('logistics.dispatch');
    $stocktakeAccess = $warehouseAccess || $user->hasPermission('logistics.approve');
    $shipper = $user->hasRole('shipper');
    $ordersAccess = $user->can('viewAny', \App\Models\Order::class);
    $scanAccess = $user->hasRole('admin') || $user->hasPermission('orders.review') || $user->shop || $shipper;
    $collectionsAccess = $user->hasRole('admin') || $shipper || $user->hasPermission('delivery.collections.view') || $user->shop;
    $financeAccess = app(\App\Policies\FinancePolicy::class)->any($user);
    $reportAccess = app(\App\Policies\ReportPolicy::class)->any($user);
    $incidentAccess = $user->can('viewAny', \App\Models\Incident::class);
    $complaintAccess = $user->can('viewAny', \App\Models\Complaint::class);
    $usersAccess = $user->can('users.manage');
    $shopsAccess = $user->can('any-shop-review');
    $catalogAccess = $user->can('catalog.manage');
    $auditAccess = $user->can('audit.view');
    $transportActive = request()->routeIs('orders.*', 'logistics.index', 'logistics.show', 'logistics.order', 'logistics.handover', 'delivery.mine', 'delivery.map');
    $warehouseActive = request()->routeIs('transfers.*', 'logistics.inventory', 'stocktakes.*');
    $financeActive = request()->routeIs('finance.*', 'reports.*', 'delivery.collections');
    $supportActive = request()->routeIs('incidents.*', 'complaints.*');
    $adminActive = request()->routeIs('users.*', 'shops.*', 'catalog.*', 'areas.*', 'warehouses.*', 'fees.*', 'audit.*');
    $accountActive = request()->routeIs('profile.*', 'notifications.*', 'shop.*', 'pickups.*');
@endphp
<nav class="portal-nav" aria-label="Menu nghiệp vụ">
    <x-portal.nav-link :href="route('dashboard')" icon="package" :active="request()->routeIs('dashboard')">Tổng quan</x-portal.nav-link>
    @if ($ordersAccess || $dispatchAccess || $warehouseAccess || $shipper || $scanAccess)
        <x-portal.nav-group label="Vận chuyển" icon="truck" :active="$transportActive" :open="request()->routeIs('dashboard')">
            @if ($ordersAccess)
                <x-portal.nav-link :href="route('orders.index')" icon="clipboard-list" :active="request()->routeIs('orders.*') && ! request()->routeIs('orders.scan*')">Vận đơn</x-portal.nav-link>
            @endif
            @if ($dispatchAccess || $warehouseAccess || $shipper)
                <x-portal.nav-link :href="route($shipper ? 'delivery.mine' : 'logistics.index')" icon="truck" :active="request()->routeIs('logistics.index', 'logistics.show', 'logistics.order', 'logistics.handover', 'delivery.mine')">{{ $shipper ? 'Nhiệm vụ của tôi' : 'Trung tâm vận hành' }}</x-portal.nav-link>
            @endif
            @if ($scanAccess)
                <x-portal.nav-link :href="route('orders.scan')" icon="qr-code" :active="request()->routeIs('orders.scan*')">Quét vận đơn</x-portal.nav-link>
            @endif
            @if ($shipper)
                <x-portal.nav-link :href="route('delivery.map')" icon="map-pin" :active="request()->routeIs('delivery.map')">Bản đồ nhiệm vụ</x-portal.nav-link>
            @endif
        </x-portal.nav-group>
    @endif
    @if ($warehouseAccess || $dispatchAccess || $stocktakeAccess)
        <x-portal.nav-group label="Kho hàng" icon="package-check" :active="$warehouseActive">
            @if ($warehouseAccess || $dispatchAccess)
                <x-portal.nav-link :href="route('transfers.index')" icon="arrow-right-left" :active="request()->routeIs('transfers.*')">Chuyển kho</x-portal.nav-link>
                <x-portal.nav-link :href="route('logistics.inventory')" icon="package-check" :active="request()->routeIs('logistics.inventory')">Tồn kho & sai lệch</x-portal.nav-link>
            @endif
            @if ($stocktakeAccess)
                <x-portal.nav-link :href="route('stocktakes.index')" icon="clipboard-list" :active="request()->routeIs('stocktakes.*')">Kiểm kê kho</x-portal.nav-link>
            @endif
        </x-portal.nav-group>
    @endif
    @if ($collectionsAccess || $financeAccess || $reportAccess)
        <x-portal.nav-group label="Tài chính & báo cáo" icon="wallet" :active="$financeActive">
            @if ($collectionsAccess)
                <x-portal.nav-link :href="route('delivery.collections')" icon="banknote" :active="request()->routeIs('delivery.collections')">Sổ thu giao hàng</x-portal.nav-link>
            @endif
            @if ($financeAccess)
                <x-portal.nav-link :href="route('finance.index')" icon="wallet" :active="request()->routeIs('finance.*')">COD & tài chính</x-portal.nav-link>
            @endif
            @if ($reportAccess)
                <x-portal.nav-link :href="route('reports.home')" icon="chart-column" :active="request()->routeIs('reports.*')">Báo cáo & thống kê</x-portal.nav-link>
            @endif
        </x-portal.nav-group>
    @endif
    @if ($incidentAccess || $complaintAccess)
        <x-portal.nav-group label="Hỗ trợ & sự cố" icon="messages-square" :active="$supportActive">
            @if ($incidentAccess)
                <x-portal.nav-link :href="route('incidents.index')" icon="triangle-alert" :active="request()->routeIs('incidents.*')">Sự cố vận chuyển</x-portal.nav-link>
            @endif
            @if ($complaintAccess)
                <x-portal.nav-link :href="route('complaints.index')" icon="messages-square" :active="request()->routeIs('complaints.*')">Khiếu nại</x-portal.nav-link>
            @endif
        </x-portal.nav-group>
    @endif
    @if ($usersAccess || $shopsAccess || $catalogAccess || $auditAccess)
        <x-portal.nav-group :label="$usersAccess || $catalogAccess || $auditAccess ? 'Quản trị' : 'Người gửi'" icon="shield-check" :active="$adminActive">
            @if ($usersAccess)
                <x-portal.nav-link :href="route('users.index')" icon="shield-check" :active="request()->routeIs('users.*')">Tài khoản & phân quyền</x-portal.nav-link>
            @endif
            @if ($shopsAccess)
                <x-portal.nav-link :href="route('shops.index')" icon="store" :active="request()->routeIs('shops.*')">Người gửi & ngân hàng</x-portal.nav-link>
            @endif
            @if ($catalogAccess)
                <x-portal.nav-link :href="route('catalog.index')" icon="map-pin" :active="request()->routeIs('catalog.*', 'areas.*', 'warehouses.*')">Khu vực & kho</x-portal.nav-link>
                <x-portal.nav-link :href="route('fees.index')" icon="receipt-text" :active="request()->routeIs('fees.*')">Phiên bản biểu phí</x-portal.nav-link>
            @endif
            @if ($auditAccess)
                <x-portal.nav-link :href="route('audit.index')" icon="scan-search" :active="request()->routeIs('audit.*')">Nhật ký thay đổi</x-portal.nav-link>
            @endif
        </x-portal.nav-group>
    @endif
    <x-portal.nav-group label="Tài khoản" icon="user-round" :active="$accountActive">
        <x-portal.nav-link :href="route('profile.edit')" icon="user-round" :active="request()->routeIs('profile.*')">Hồ sơ cá nhân</x-portal.nav-link>
        <x-portal.nav-link :href="route('notifications.index')" icon="bell" :active="request()->routeIs('notifications.*')">Thông báo</x-portal.nav-link>
        @if ($user->shop)
            <x-portal.nav-link :href="route('shop.profile')" icon="map-pin-house" :active="request()->routeIs('shop.*', 'pickups.*')">{{ $user->shop->isIndividual() ? 'Người gửi & địa chỉ' : 'Cửa hàng & địa chỉ' }}</x-portal.nav-link>
        @endif
    </x-portal.nav-group>
</nav>
<div class="portal-user">
    <strong>{{ $user->name }}</strong><small>{{ $user->roles->pluck('label')->join(', ') }}</small>
    <small>{{ $user->hasRole('admin') ? 'Toàn hệ thống' : ($user->warehouses->pluck('name')->join(', ') ?: ($user->shop ? 'Dữ liệu người gửi của bạn' : 'Chưa gán phạm vi kho')) }}</small>
</div>
