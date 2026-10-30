{{-- Chỉ tạo link route private theo ID; Controller kiểm quyền hồ sơ và tính nội bộ trước khi trả tệp. --}}
@if ($attachments->isNotEmpty())
    <ul class="case-files">
        @foreach ($attachments as $attachment)
            <li><a href="{{ route('cases.attachment', $attachment) }}" target="_blank" rel="noopener"><i
                        data-lucide="paperclip"></i>
                    {{ $attachment->original_name }}</a><small>{{ number_format($attachment->size / 1024, 0) }}
                    KB</small></li>
        @endforeach
    </ul>
@endif
