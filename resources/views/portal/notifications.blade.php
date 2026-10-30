@extends('layouts.portal')
@section('heading', 'Thông báo')
@section('actions')
    <form method="post" action="{{ route('notifications.read-all') }}">
        @csrf
        <button class="btn btn-outline-primary" @disabled($unreadCount === 0)><i data-lucide="check-check"></i> Đọc tất cả</button>
    </form>
@endsection
@section('content')
    <nav class="order-status-tabs" aria-label="Trạng thái thông báo">
        @foreach (['all' => 'Tất cả', 'unread' => 'Chưa đọc', 'read' => 'Đã đọc'] as $key => $label)
            <a @class(['active' => request('state', 'all') === $key]) href="{{ route('notifications.index', ['state' => $key, 'category' => request('category')]) }}">
                {{ $label }} @if ($key === 'unread')<span>{{ $unreadCount }}</span>@endif
            </a>
        @endforeach
    </nav>
    <form method="get" class="notification-filters">
        <input type="hidden" name="state" value="{{ request('state', 'all') }}">
        <x-field name="category" label="Nhóm thông báo" :value="request('category')" :options="$categories" placeholder="Tất cả" />
        <button class="btn btn-outline-primary"><i data-lucide="filter"></i> Lọc</button>
    </form>
    <div class="notification-list">
        @forelse ($notifications as $notification)
            @php($data = $notification->data)
            <article @class(['notification-row', 'is-unread' => $notification->read_at === null])>
                <div class="notification-symbol" aria-hidden="true">
                    <i data-lucide="{{ ['order' => 'package', 'task' => 'truck', 'delivery' => 'triangle-alert', 'incident' => 'triangle-alert', 'finance' => 'wallet'][$data['category']] ?? 'bell' }}"></i>
                </div>
                <div class="notification-body">
                    <a href="{{ route('notifications.open', $notification) }}">{{ $data['title'] }}</a>
                    <span class="notification-reference">{{ $data['reference'] }}</span>
                    <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('d/m/Y H:i') }}</time>
                </div>
                <form method="post" action="{{ route('notifications.update', $notification) }}">
                    @csrf
                    @method('patch')
                    <input type="hidden" name="read" value="{{ $notification->read_at ? 0 : 1 }}">
                    <button class="btn icon-button" title="{{ $notification->read_at ? 'Đánh dấu chưa đọc' : 'Đánh dấu đã đọc' }}" aria-label="{{ $notification->read_at ? 'Đánh dấu chưa đọc' : 'Đánh dấu đã đọc' }}">
                        <i data-lucide="{{ $notification->read_at ? 'mail' : 'mail-check' }}"></i>
                    </button>
                </form>
            </article>
        @empty
            <div class="notification-empty"><i data-lucide="bell"></i><p>Chưa có thông báo phù hợp.</p></div>
        @endforelse
    </div>
    {{ $notifications->links() }}
@endsection
