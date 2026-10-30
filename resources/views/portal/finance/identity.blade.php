{{-- Nhận document từ view cha để thống nhất mã, trạng thái, người lập và lý do trên mọi loại chứng từ. --}}
<div class="case-identity">
    <strong class="logistics-code">{{ $document->code }}</strong>
    <span class="status-badge state-{{ $document->status }}">{{ $document::STATUSES[$document->status] }}</span>
    <span class="small text-secondary">{{ $document->warehouse->name }} · {{ $document->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</span>
</div>
<dl class="detail-grid mt-4">
    <dt>Người lập</dt><dd>{{ $document->creator->name }}</dd>
    <dt>Nội dung</dt><dd>{{ $document->reason }}</dd>
</dl>
