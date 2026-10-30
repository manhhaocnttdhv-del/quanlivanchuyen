@extends('layouts.portal')
@section('heading', 'Lập điều chỉnh tài chính')
@section('content')
    @include('portal.finance.nav', ['section' => 'adjustments'])
    @if (! $order)
        <form class="filter-bar my-4" method="get"><x-field name="q" label="Mã vận đơn" :value="$filters['q'] ?? ''" /><button class="btn btn-primary"><i data-lucide="search"></i> Tìm đơn</button></form>
        @foreach ($orders as $candidate)<div class="finance-source-row"><span class="order-code">{{ $candidate->tracking_code }}</span><a class="btn btn-outline-primary" href="{{ route('finance.adjustments.create', ['order_id' => $candidate->id]) }}"><i data-lucide="arrow-right"></i> Chọn đơn</a></div>@endforeach
        {{ $orders->links() }}
    @else
        <p class="order-code">{{ $order->tracking_code }}</p>
        <form method="post" action="{{ route('finance.adjustments.store', $order) }}" enctype="multipart/form-data" class="finance-form">
            @csrf<input type="hidden" name="client_token" value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row g-3"><div class="col-md-7"><x-field name="kind" label="Loại điều chỉnh" :options="\App\Models\Adjustment::KINDS" data-finance-kind required /></div><div class="col-md-5"><x-field name="amount" type="number" label="Số tiền theo căn cứ (đ)" min="1" max="9999999999999" step="1" required /></div></div>
            <div data-finance-basis="incident"><x-field name="incident_id" label="Kết luận sự cố đã duyệt" :options="['' => 'Chọn căn cứ'] + $incidents->mapWithKeys(fn ($item) => [$item->id => $item->code.' · '.\App\Models\Incident::OUTCOMES[$item->approvedAction->payload['outcome']].' · '.number_format((int) $item->approvedAction->proposed_amount, 0, ',', '.').'đ'])->all()" /></div>
            <div data-finance-basis="reversal"><x-field name="reverses_adjustment_id" label="Điều chỉnh gốc cần đảo" :options="['' => 'Chọn chứng từ gốc'] + $originals->mapWithKeys(fn ($item) => [$item->id => $item->code.' · '.\App\Models\Adjustment::KINDS[$item->kind].' · '.number_format((int) $item->amount, 0, ',', '.').'đ'])->all()" /></div>
            <div data-finance-basis="collection"><x-field name="delivery_collection_id" label="Khoản thu gốc" :options="['' => 'Chọn khoản thu'] + $order->collections->mapWithKeys(fn ($item) => [$item->id => '#'.$item->id.' · '.\App\Models\DeliveryCollection::TYPES[$item->type].' · '.number_format((int) $item->amount, 0, ',', '.').'đ'])->all()" /></div>
            <div data-finance-basis="fee"><x-field name="receivable_id" label="Phí gốc" :options="['' => 'Chọn phí'] + $fees->mapWithKeys(fn ($item) => [$item->id => '#'.$item->id.' · '.\App\Models\Receivable::TYPES[$item->type].' · '.number_format((int) $item->amount, 0, ',', '.').'đ'])->all()" /></div>
            <x-field name="reason" label="Căn cứ và nội dung điều chỉnh" type="textarea" rows="3" minlength="5" maxlength="2000" required />
            <x-field name="evidence" label="Chứng từ làm căn cứ" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required />
            <button class="btn btn-primary mt-3"><i data-lucide="send"></i> Gửi duyệt điều chỉnh</button>
        </form>
    @endif
@endsection
