@extends('layouts.portal')
{{-- Controller đã loại trao đổi nội bộ khỏi query của shop; revision bảo vệ các form cập nhật đồng thời. --}}
@section('heading', 'Chi tiết khiếu nại')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('complaints.index') }}">
    <i data-lucide="arrow-left"></i> Khiếu nại</a>@endsection
@section('content')
    <div class="case-identity"><strong class="logistics-code">{{ $complaint->code }}</strong><span
            class="status-badge">{{ \App\Models\Complaint::STATUSES[$complaint->status] }}</span>
        @if ($complaint->isOverdue())
            <span class="case-priority priority-critical">Quá hạn xử lý</span>
        @endif
    </div>
    <h2 class="case-title mt-4">{{ $complaint->title }}</h2>
    <dl class="detail-grid mt-4">
        <dt>Vận đơn</dt>
        <dd>{{ $complaint->order->tracking_code }} @can('view', $complaint->order)
                <a class="ms-2" href="{{ route('orders.show', $complaint->order) }}">Chi tiết</a>
            @endcan
        </dd>
        <dt>Cửa hàng</dt>
        <dd>{{ $complaint->shop->name }}</dd>
        <dt>Nội dung</dt>
        <dd>{{ \App\Models\Complaint::TYPES[$complaint->type] }}</dd>
        <dt>Phụ trách</dt>
        <dd>{{ $complaint->assignee?->name ?? 'Chờ tiếp nhận' }}</dd>
        <dt>Hạn xử lý</dt>
        <dd>{{ $complaint->due_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</dd>
        <dt>Phản hồi đầu</dt>
        <dd>{{ $complaint->first_response_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') ?? 'Chưa có phản hồi' }}
        </dd>
    </dl>
    @if ($complaint->resolution)
        <section class="work-section">
            <h2>Kết quả xử lý</h2>
            <p class="case-text mt-3">{{ $complaint->resolution }}</p>
        </section>
    @endif
    <div class="row g-4">
        <div class="col-xl-8">
            @if ($complaint->status !== 'closed')
                <section class="work-section">
                    <h2>Trao đổi</h2>
                    <form method="post" enctype="multipart/form-data"
                        action="{{ route('complaints.action', [$complaint, 'message']) }}" class="mt-3">
                        @csrf<input type="hidden" name="revision" value="{{ $complaint->revision }}">
                        @if ($staff)
                        <x-field name="is_internal" label="Phạm vi phản hồi" :options="[0 => 'Gửi cho shop', 1 => 'Trao đổi nội bộ']" required />@else<input
                                type="hidden" name="is_internal" value="0">
                        @endif
                        <x-field name="body" label="Nội dung trao đổi" type="textarea" rows="4" minlength="2"
                            maxlength="5000" required />
                        <x-field name="evidence" type="file" label="Tệp đính kèm"
                            accept="image/jpeg,image/png,image/webp,application/pdf" />
                        <button class="btn btn-primary mt-3"><i data-lucide="send"></i> Gửi phản hồi</button>
                    </form>
                </section>
            @elseif ($complaint->closed_at->copy()->addDays(config('cases.complaint_reopen_days'))->isFuture())
                <section class="work-section">
                    <details>
                        <summary>Yêu cầu mở lại khiếu nại</summary>
                        <form method="post" action="{{ route('complaints.action', [$complaint, 'reopen']) }}"
                            class="mt-3">@csrf<input type="hidden" name="revision"
                                value="{{ $complaint->revision }}"><x-field name="reason" id="reopen-reason"
                                label="Nội dung cần xem xét lại" type="textarea" rows="3" minlength="10"
                                maxlength="2000" required /><button class="btn btn-outline-primary mt-3"><i
                                    data-lucide="rotate-ccw"></i> Mở lại</button></form>
                    </details>
                </section>
            @endif
            <section class="work-section">
                <h2>Lịch sử trao đổi</h2>
                {{-- Đây đã là tập tin nhắn được lọc quyền trước paginate; không dùng CSS để giấu tin nội bộ. --}}
                @foreach ($messages as $message)
                    <article class="case-entry {{ $message->is_internal ? 'case-internal' : '' }}">
                        <div class="case-entry-heading">
                            <strong>{{ $message->actor->name }}</strong><time>{{ $message->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time>
                        </div>
                        @if ($message->is_internal)
                            <span class="case-internal-label"><i data-lucide="lock-keyhole"></i> Nội bộ</span>
                        @endif
                        <p class="case-text mt-3">{{ $message->body }}</p>@include('portal.cases.attachments', ['attachments' => $message->attachments])
                    </article>
                @endforeach
                {{ $messages->links() }}
            </section>
        </div>
        @if ($staff && $complaint->status !== 'closed')
            <aside class="col-xl-4">
                <section class="work-section">
                    <h2>Tiếp nhận / phân công</h2>
                    <form method="post" action="{{ route('complaints.action', [$complaint, 'assign']) }}" class="mt-3">
                        @csrf<input type="hidden" name="revision" value="{{ $complaint->revision }}"><x-field
                            name="assigned_to" label="Người xử lý" :options="$handlers" :value="$complaint->assigned_to"
                            placeholder="Chọn nhân viên" required /><x-field name="reason" id="assign-reason"
                            label="Căn cứ phân công" minlength="5" maxlength="2000" required /><button
                            class="btn btn-outline-primary mt-3"><i data-lucide="save"></i> Phân công</button>
                    </form>
                </section>
                <section class="work-section">
                    <h2>Sự cố liên quan</h2>
                    @if ($complaint->incident_id)
                        @php($linked = $incidents->firstWhere('id', $complaint->incident_id))
                        @if ($linked)
                            <p class="mt-3">@can('view', $linked)<a class="logistics-code"
                                    href="{{ route('incidents.show', $linked) }}">{{ $linked->code }}</a>@else<span class="logistics-code">{{ $linked->code }}</span>@endcan ·
                                {{ \App\Models\Incident::STATUSES[$linked->status] }}</p>
                        @endif
                    @else
                        @if (app(\App\Policies\IncidentPolicy::class)->createForOrder(auth()->user(), $complaint->order))<a href="{{ route('incidents.create', ['order_id' => $complaint->order_id]) }}"
                            class="btn btn-outline-primary mt-3"><i data-lucide="triangle-alert"></i> Mở sự cố</a>@endif
                        <form method="post" action="{{ route('complaints.action', [$complaint, 'link']) }}"
                            class="mt-3">
                            @csrf<input type="hidden" name="revision" value="{{ $complaint->revision }}"><x-field
                                name="incident_id" label="Hồ sơ xác minh cùng vận đơn" :options="$incidents->pluck('code', 'id')"
                                placeholder="Chọn sự cố" required /><x-field name="reason" id="link-reason"
                                label="Căn cứ liên kết" minlength="5" maxlength="2000" required /><button
                                class="btn btn-outline-primary mt-3" @disabled($incidents->isEmpty())><i
                                    data-lucide="paperclip"></i> Liên kết</button>
                        </form>
                    @endif
                </section>
                <section class="work-section">
                    <h2>Kết thúc xử lý</h2>
                    <form method="post" action="{{ route('complaints.action', [$complaint, 'close']) }}" class="mt-3"
                        data-confirm="Xác nhận kết quả gửi shop và đóng khiếu nại?">
                        @csrf<input type="hidden" name="revision" value="{{ $complaint->revision }}"><x-field
                            name="resolution" label="Kết quả và phản hồi gửi shop" type="textarea" rows="4"
                            minlength="10" maxlength="5000" required /><button class="btn btn-primary mt-3"><i
                                data-lucide="circle-check"></i> Giải quyết khiếu nại</button>
                    </form>
                </section>
            </aside>
        @endif
    </div>
@endsection
