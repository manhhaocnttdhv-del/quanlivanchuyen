@extends('layouts.portal')
@section('heading', $document ? 'Cập nhật đối soát nháp' : 'Lập đối soát shop')
@section('content')
    @include('portal.finance.nav', ['section' => 'reconciliations'])
    @if (! $document)
        <form method="get" class="filter-bar my-4">
            <x-field name="warehouse_id" label="Kho" :options="$warehouses" :value="$filters['warehouse_id']" required />
            <x-field name="shop_id" label="Shop" :options="$shops" :value="$filters['shop_id']" required />
            <x-field name="from" label="Đầu kỳ" type="date" :value="$filters['from']" required />
            <x-field name="until" label="Cuối kỳ" type="date" :value="$filters['until']" required />
            <button class="btn btn-outline-primary"><i data-lucide="search"></i> Lấy nguồn đối soát</button>
        </form>
    @else
        <p class="small text-secondary">{{ $document->code }} · {{ $shops[$filters['shop_id']] ?? '' }} · {{ $filters['from'] }} đến {{ $filters['until'] }}</p>
    @endif
    <form method="post" action="{{ $document ? route('finance.reconciliations.action', [$document, 'replace']) : route('finance.reconciliations.store') }}" class="finance-form">
        @csrf
        @if ($document)<input type="hidden" name="revision" value="{{ $document->revision }}">
        @else
            <input type="hidden" name="client_token" value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="warehouse_id" value="{{ $filters['warehouse_id'] }}"><input type="hidden" name="shop_id" value="{{ $filters['shop_id'] }}">
            <input type="hidden" name="period_start" value="{{ $filters['from'] }}"><input type="hidden" name="period_end" value="{{ $filters['until'] }}">
        @endif
        @foreach (['cod' => ['label' => 'COD đã về quỹ', 'sources' => $cod], 'fees' => ['label' => 'Phí shop chịu', 'sources' => $fees]] as $group => $set)
            <section class="work-section"><h2>{{ $set['label'] }}</h2>
                <div class="table-responsive"><table class="table align-middle finance-table"><thead><tr><th>Vận đơn / nguồn</th><th class="text-end">Khả dụng</th><th class="text-end">Đối soát (đ)</th></tr></thead><tbody>
                    @forelse ($set['sources'] as $source)
                        @php($selected = $document?->lines->firstWhere($group === 'cod' ? 'delivery_collection_id' : 'receivable_id', $source->id))
                        <tr><td><span class="order-code">{{ $source->order->tracking_code }}</span><small class="d-block">#{{ $source->id }} · {{ $group === 'cod' ? 'COD thu hộ' : \App\Models\Receivable::TYPES[$source->type] }}</small></td>
                            <td class="text-end" data-label="Khả dụng">{{ number_format($queries->available($source), 0, ',', '.') }}đ</td><td data-label="Đối soát (đ)"><x-finance-amount :group="$group" :source="$source->id" :label="'Đối soát '.$group.' #'.$source->id" :value="(int) ($selected?->amount ?? 0)" :max="$queries->available($source)" /></td></tr>
                    @empty<tr><td colspan="3" class="text-center text-secondary py-4">Không có nguồn đủ điều kiện trong kỳ.</td></tr>@endforelse
                </tbody></table></div>
                @if ($set['sources']->count() === 100)<p class="small text-secondary">100 nguồn đầu trong kỳ đã chọn.</p>@endif
            </section>
        @endforeach
        <section class="work-section"><h2>Bồi thường đã duyệt</h2>
            @forelse ($adjustments as $adjustment)
                <label class="finance-source-row"><span><input class="form-check-input me-2" type="checkbox" name="adjustment_ids[]" value="{{ $adjustment->id }}" @checked(in_array($adjustment->id, old('adjustment_ids', $document?->lines->pluck('adjustment_id')->filter()->all() ?? [])))>{{ $adjustment->code }}<small class="d-block text-secondary">{{ $adjustment->order->tracking_code }}</small></span><strong>{{ number_format((int) $adjustment->amount, 0, ',', '.') }}đ</strong></label>
            @empty<p class="small text-secondary">Không có khoản bồi thường khả dụng.</p>@endforelse
        </section>
        <x-field name="reason" label="Căn cứ lập / cập nhật đối soát" type="textarea" rows="3" minlength="5" maxlength="2000" required />
        <button class="btn btn-primary mt-3"><i data-lucide="save"></i> {{ $document ? 'Lưu phiên bản mới' : 'Tạo bản nháp' }}</button>
    </form>
@endsection
