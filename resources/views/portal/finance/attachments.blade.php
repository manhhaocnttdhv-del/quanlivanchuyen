@if ($document->attachments->isNotEmpty())
    <ul class="case-files">
        @foreach ($document->attachments as $file)
            <li><i data-lucide="paperclip"></i><a href="{{ route('finance.attachment', $file) }}">{{ $file->original_name }}</a><small>{{ number_format($file->size / 1024, 0) }} KB</small></li>
        @endforeach
    </ul>
@endif
