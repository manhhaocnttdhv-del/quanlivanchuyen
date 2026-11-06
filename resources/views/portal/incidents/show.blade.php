@extends('layouts.portal')
{{-- Phân biệt người xử lý và người duyệt kết luận; mỗi form mang revision, bằng chứng đi qua route private. --}}
@section('heading', 'Chi tiết sự cố')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('incidents.index') }}">
    <i data-lucide="arrow-left"></i> Sự cố</a>@endsection
@section('content')
    <div class="case-identity"><strong class="logistics-code">{{ $incident->code }}</strong><span
            class="status-badge">{{ \App\Models\Incident::STATUSES[$incident->status] }}</span><span
            class="case-priority priority-{{ $incident->severity }}">{{ \App\Models\Incident::SEVERITIES[$incident->severity] }}</span>
    </div>
    <h2 class="case-title mt-4">{{ $incident->title }}</h2>
    <p class="case-text">{{ $incident->description }}</p>
    <dl class="detail-grid mt-4">
        <dt>Vận đơn</dt>
        <dd>{{ $incident->order->tracking_code }} @can('view', $incident->order)
                <a class="ms-2" href="{{ route('orders.show', $incident->order) }}">Chi tiết</a>
            @endcan
        </dd>
        <dt>Kho / loại</dt>
        <dd>{{ $incident->warehouse->name }} · {{ \App\Models\Incident::TYPES[$incident->type] }}</dd>
        <dt>Người mở</dt>
        <dd>{{ $incident->creator->name }}</dd>
        <dt>Phụ trách</dt>
        <dd>{{ $incident->assignee?->name ?? 'Chưa phân công' }}</dd>
        <dt>Khóa hiện tại</dt>
        <dd>{{ $incident->isDispatchBlocked() ? 'Khóa giao / hoàn' : 'Không khóa giao' }} ·
            {{ $incident->blocks_reconciliation ? 'Khóa đối soát' : 'Không khóa đối soát' }}</dd>
    </dl>
    @if ($incident->financial_status === 'pending_finance')
        <p class="alert alert-warning">Kết luận tài chính đã được duyệt, đang chờ chứng từ xử lý tài chính. Chưa xác nhận đã
            bồi thường hoặc điều chỉnh tiền; khóa đối soát vẫn giữ.</p>
    @endif
    @if ($incident->type === 'lost' && $incident->status === 'closed' && ($incident->approvedAction?->payload['outcome'] ?? null) === 'compensation')
        <p class="alert alert-danger">Kiện đã xác nhận mất không được giao tiếp. Phần hàng còn lại phải trả về kho và tiếp tục giữ xử lý; duyệt bồi thường không cho phép giao lại.</p>
    @endif
    <div class="row g-4">
        <div class="col-xl-8">
            @if ($incident->proposal)
                <section class="work-section">
                    <h2>Kết luận {{ $incident->status === 'closed' ? 'đã duyệt' : 'đề xuất' }}</h2>
                    <p class="mt-3">{{ \App\Models\Incident::OUTCOMES[$incident->proposal->payload['outcome']] }} ·
                        <strong>{{ number_format((int) $incident->proposal->proposed_amount, 0, ',', '.') }}đ</strong>
                    </p>
                    <p class="case-text">{{ $incident->proposal->payload['conclusion'] }}</p>
                    <p class="small text-secondary">Người đề xuất: {{ $incident->proposal->actor->name }}</p>
                </section>
            @endif
            @if (
                $incident->status === 'in_review' &&
                    $policy->approve(auth()->user(), $incident) &&
                    auth()->id() !== $incident->created_by &&
                    auth()->id() !== $incident->proposal?->created_by)
                <section class="work-section">
                    <h2>Xét duyệt kết luận</h2>
                    <form method="post" action="{{ route('incidents.action', [$incident, 'review']) }}" class="mt-3"
                        data-confirm="Xác nhận kết quả xét duyệt? Duyệt sẽ kết thúc xác minh và cập nhật phạm vi khóa.">
                        @csrf<input type="hidden" name="revision" value="{{ $incident->revision }}">
                        <x-field name="decision" label="Kết quả xét duyệt" :options="['approved' => 'Duyệt kết luận', 'rejected' => 'Trả lại xác minh']" required />
                        <x-field name="reason" id="review-reason" label="Căn cứ xét duyệt" type="textarea" rows="3"
                            minlength="5" maxlength="2000" required />
                        <button class="btn btn-primary mt-3"><i data-lucide="shield-check"></i> Lưu xét duyệt</button>
                    </form>
                </section>
            @endif
            @if ($incident->status !== 'closed')
                <section class="work-section">
                    <h2>Ghi nhận xác minh</h2>
                    <form method="post" enctype="multipart/form-data"
                        action="{{ route('incidents.action', [$incident, 'note']) }}" class="mt-3">
                        @csrf<input type="hidden" name="revision" value="{{ $incident->revision }}">
                        <x-field name="reason" id="note-reason" label="Diễn biến / kết quả xác minh" type="textarea"
                            rows="3" minlength="5" maxlength="2000" required />
                        <x-field name="evidence" id="note-evidence" type="file" label="Bằng chứng bổ sung"
                            accept="image/jpeg,image/png,image/webp,application/pdf" />
                        <button class="btn btn-outline-primary mt-3"><i data-lucide="send"></i> Lưu ghi nhận</button>
                    </form>
                </section>
            @endif
            <section class="work-section">
                <h2>Lịch sử xử lý</h2>
                @foreach ($actions as $action)
                    <article class="case-entry">
                        <div class="case-entry-heading">
                            <strong>{{ \App\Models\IncidentAction::TYPES[$action->type] }}</strong><time>{{ $action->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time>
                        </div>
                        <p class="small text-secondary mt-2">{{ $action->actor->name }}</p>
                        <p class="case-text">{{ $action->reason }}</p>
                        @if (isset($action->payload['conclusion']))
                            <p class="case-text">{{ $action->payload['conclusion'] }}</p>
                            <p>{{ \App\Models\Incident::OUTCOMES[$action->payload['outcome']] }} ·
                                {{ number_format((int) $action->proposed_amount, 0, ',', '.') }}đ</p>
                        @endif
                        @include('portal.cases.attachments', ['attachments' => $action->attachments])
                    </article>
                @endforeach
                {{ $actions->links() }}
            </section>
        </div>
        <aside class="col-xl-4">
            @if ($policy->manage(auth()->user(), $incident) && $incident->status !== 'closed')
                <section class="work-section">
                    <h2>Phân công</h2>
                    <form method="post" action="{{ route('incidents.action', [$incident, 'assign']) }}" class="mt-3">
                        @csrf<input type="hidden" name="revision" value="{{ $incident->revision }}"><x-field
                            name="assigned_to" label="Người xử lý" :options="$handlers" :value="$incident->assigned_to"
                            placeholder="Chọn nhân viên" required /><x-field name="reason" id="assign-reason"
                            label="Căn cứ phân công" minlength="5" maxlength="2000" required /><button
                            class="btn btn-outline-primary mt-3"><i data-lucide="save"></i> Phân công</button>
                    </form>
                </section>
                @if ($incident->status === 'open')
                    <section class="work-section">
                        <h2>Bổ sung khóa</h2>
                        <form method="post" action="{{ route('incidents.action', [$incident, 'hold']) }}" class="mt-3">
                            @csrf<input type="hidden" name="revision" value="{{ $incident->revision }}">
                            <input type="hidden" name="blocks_dispatch" value="0"><label
                                class="form-check mb-3"><input class="form-check-input" type="checkbox"
                                    name="blocks_dispatch" value="1" @checked($incident->blocks_dispatch)> Khóa giao /
                                hoàn</label>
                            <input type="hidden" name="blocks_reconciliation" value="0"><label
                                class="form-check mb-3"><input class="form-check-input" type="checkbox"
                                    name="blocks_reconciliation" value="1" @checked($incident->blocks_reconciliation)> Khóa đối
                                soát</label>
                            <x-field name="reason" id="hold-reason" label="Lý do khóa" minlength="5" maxlength="2000"
                                required /><button class="btn btn-outline-danger mt-3"><i data-lucide="lock-keyhole"></i>
                                Bổ sung khóa</button>
                        </form>
                    </section>
                    <section class="work-section">
                        <h2>Đề xuất kết luận</h2>
                        <form method="post" enctype="multipart/form-data"
                            action="{{ route('incidents.action', [$incident, 'propose']) }}" class="mt-3">
                            @csrf<input type="hidden" name="revision" value="{{ $incident->revision }}">
                            <x-field name="outcome" label="Hướng kết luận" :options="$incident->type === 'lost' ? ['no_financial' => 'Đã tìm đủ kiện về kho, không bồi thường', 'compensation' => 'Xác nhận mất và đề xuất bồi thường'] : \App\Models\Incident::OUTCOMES" required />
                            <x-field name="amount" type="number" label="Số tiền đề xuất (đ)" value="0"
                                min="0" max="9999999999999" step="1" required />
                            <x-field name="conclusion" label="Kết luận và phương án xử lý" type="textarea"
                                rows="4" minlength="10" maxlength="5000" required />
                            <x-field name="reason" id="proposal-reason" label="Căn cứ đề xuất" type="textarea"
                                rows="2" minlength="5" maxlength="2000" required />
                            <x-field name="evidence" id="proposal-evidence" type="file" label="Tài liệu kết luận"
                                accept="image/jpeg,image/png,image/webp,application/pdf" />
                            <button class="btn btn-primary mt-3"><i data-lucide="send"></i> Gửi duyệt kết luận</button>
                        </form>
                    </section>
                @endif
            @endif
        </aside>
    </div>
@endsection
