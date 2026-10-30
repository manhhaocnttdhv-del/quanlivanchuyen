<section class="work-section"><h2>Đối soát liên quan</h2>
    @forelse ($source->reconciliationLines as $line)
        <div class="finance-source-row"><span>@if ($policy->view(auth()->user(), $line->reconciliation))<a href="{{ route('finance.reconciliations.show', $line->reconciliation) }}">{{ $line->reconciliation->code }}</a>@else{{ $line->reconciliation->code }}@endif<small class="d-block">Phiên bản {{ $line->version }}{{ $line->version !== $line->reconciliation->line_version ? ' · đã thay thế' : '' }}</small></span><span>{{ \App\Models\Reconciliation::STATUSES[$line->reconciliation->status] }}</span><strong>{{ number_format((int) $line->amount, 0, ',', '.') }}đ</strong></div>
    @empty<p class="text-secondary small">Chưa vào đối soát.</p>@endforelse
</section>
