{{-- Lịch sử finance_events đã phân trang; đây là căn cứ diễn biến, không cho sửa/xóa trực tiếp. --}}
<section class="work-section">
    <h2>Lịch sử chứng từ</h2>
    @foreach ($events as $event)
        <div class="case-entry">
            <div class="case-entry-heading"><strong>{{ \App\Models\FinanceEvent::LABELS[$event->event] ?? $event->event }}</strong><time>{{ $event->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time></div>
            <div class="small text-secondary mt-2">{{ $event->actor->name }}</div>
            <p class="case-text mb-0">{{ $event->reason }}</p>
        </div>
    @endforeach
    {{ $events->links() }}
</section>
