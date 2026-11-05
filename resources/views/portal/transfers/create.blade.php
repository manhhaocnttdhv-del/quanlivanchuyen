@if ($order->status === 'at_warehouse' && !$routeReady && $policy->warehouse(auth()->user(), $order->warehouse_id))
    <section class="work-section">
        <h2>Xuất chuyển kho</h2>
        <form method="post" action="{{ route('transfers.store', $order) }}" class="narrow-form mt-3" data-confirm="Xác nhận xuất đủ kiện theo chuyến chuyển kho?">
            @csrf<input type="hidden" name="revision" value="{{ $order->revision }}">
            <x-field name="destination_warehouse_id" label="Kho nhận" :options="$destinations" placeholder="Chọn kho đích" required />
            <x-field name="carrier" label="Đơn vị / người chịu trách nhiệm vận chuyển" maxlength="150" required />
            <x-field name="transport_reference" label="Biển số xe / mã chuyến" maxlength="150" required />
            <x-field name="reason" id="transfer-reason" label="Căn cứ xuất chuyển" type="textarea" minlength="5" maxlength="1000" required />
            <button class="btn btn-primary mt-3" @disabled($destinations->isEmpty())><i data-lucide="truck"></i> Xuất chuyển kho</button>
        </form>
    </section>
@endif
