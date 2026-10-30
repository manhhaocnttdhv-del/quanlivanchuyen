@extends('layouts.portal')
{{-- Các tham chiếu kiện/task/khoản thu thuộc đơn đã chọn; hai khóa giao và đối soát là độc lập. --}}
@section('heading', 'Mở hồ sơ sự cố')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('incidents.index') }}"><i
        data-lucide="arrow-left"></i> Sự cố</a>@endsection
@section('content')
    @if (!$order)
        @include('portal.cases.order-picker', ['pickerRoute' => 'incidents.create'])
    @else
        <div class="case-identity"><strong
                class="logistics-code">{{ $order->tracking_code }}</strong><span>{{ $order->shop->name }}</span><a
                href="{{ route('incidents.create') }}">Đổi vận đơn</a></div>
        <form method="post" enctype="multipart/form-data" action="{{ route('incidents.store', $order) }}"
            class="narrow-form mt-4">
            @csrf<input type="hidden" name="client_token"
                value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row g-3">
                <div class="col-12"><x-field name="title" label="Tiêu đề sự cố" minlength="5" maxlength="180" required />
                </div>
                <div class="col-sm-6"><x-field name="type" label="Loại sự cố" :options="\App\Models\Incident::TYPES" required /></div>
                <div class="col-sm-6"><x-field name="severity" label="Mức độ" :options="\App\Models\Incident::SEVERITIES" required /></div>
                <div class="col-12"><x-field name="description" label="Diễn biến và căn cứ ban đầu" type="textarea"
                        rows="4" minlength="10" maxlength="5000" required /></div>
                <div class="col-12"><x-field name="evidence" type="file" label="Bằng chứng"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        hint="JPG, PNG, WebP hoặc PDF, tối đa 5 MB. Bắt buộc khi mất/hỏng hàng hoặc sai tiền." /></div>
            </div>
            <fieldset class="work-section mt-4">
                <legend class="h6">Phạm vi khóa</legend>
                <input type="hidden" name="blocks_dispatch" value="0"><label class="form-check mb-3"><input
                        class="form-check-input" type="checkbox" name="blocks_dispatch" value="1"
                        @checked(old('blocks_dispatch', true))> Khóa lấy, xuất giao và hoàn hàng</label>
                <input type="hidden" name="blocks_reconciliation" value="0"><label class="form-check"><input
                        class="form-check-input" type="checkbox" name="blocks_reconciliation" value="1"
                        @checked(old('blocks_reconciliation', false))> Khóa đối soát tài chính</label>
                <p class="small text-secondary mt-3">Sai tiền luôn khóa đối soát. Bàn giao trả kiện về kho vẫn được tiếp
                    nhận.</p>
            </fieldset>
            <details class="work-section">
                <summary>Liên kết kiện / chứng từ liên quan</summary>
                <div class="row g-3 mt-1">
                    <div class="col-sm-6"><x-field name="warehouse_item_id" label="Kiện" :options="['' => 'Toàn vận đơn'] +
                        $order->parcels
                            ->mapWithKeys(fn($item) => [$item->id => 'Kiện #' . $item->parcel_number])
                            ->all()" /></div>
                    <div class="col-sm-6"><x-field name="delivery_task_id" label="Nhiệm vụ" :options="['' => 'Không chọn'] +
                        $order->tasks
                            ->mapWithKeys(
                                fn($task) => [
                                    $task->id => '#' . $task->id . ' · ' . \App\Models\DeliveryTask::TYPES[$task->type],
                                ],
                            )
                            ->all()" /></div>
                    <div class="col-sm-6"><x-field name="delivery_collection_id" label="Khoản thu" :options="['' => 'Không chọn'] +
                        $order->collections
                            ->mapWithKeys(
                                fn($entry) => [
                                    $entry->id =>
                                        '#' . $entry->id . ' · ' . \App\Models\DeliveryCollection::TYPES[$entry->type],
                                ],
                            )
                            ->all()" />
                    </div>
                    <div class="col-sm-6"><x-field name="discrepancy_id" label="Sai lệch kho" :options="['' => 'Không chọn'] +
                        $order->discrepancies
                            ->mapWithKeys(
                                fn($entry) => [
                                    $entry->id =>
                                        '#' . $entry->id . ' · ' . \App\Models\Discrepancy::TYPES[$entry->type],
                                ],
                            )
                            ->all()" /></div>
                </div>
            </details>
            <button class="btn btn-primary mt-3"><i data-lucide="triangle-alert"></i> Mở hồ sơ sự cố</button>
        </form>
    @endif
@endsection
