<section class="work-section"><h2>Lịch sử điều chỉnh</h2>
    @forelse ($source->adjustmentLines as $line)
        <div class="finance-source-row"><span>@if ($policy->view(auth()->user(), $line->adjustment))<a href="{{ route('finance.adjustments.show', $line->adjustment) }}">{{ $line->adjustment->code }}</a>@else{{ $line->adjustment->code }}@endif<small class="d-block">{{ \App\Models\Adjustment::KINDS[$line->adjustment->kind] }}</small></span><span>{{ \App\Models\Adjustment::STATUSES[$line->adjustment->status] }}</span><strong>{{ number_format((int) $line->amount, 0, ',', '.') }}đ</strong></div>
    @empty<p class="text-secondary small">Chưa có điều chỉnh.</p>@endforelse
</section>
