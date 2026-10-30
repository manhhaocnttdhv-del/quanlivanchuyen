@extends('layouts.portal')
{{-- Chọn đơn trong shop trước khi gửi; client_token chống gửi lặp, SLA do server đặt. --}}
@section('heading', 'Gửi khiếu nại')
@section('actions')<a class="btn btn-outline-primary" href="{{ route('complaints.index') }}"><i
        data-lucide="arrow-left"></i> Khiếu nại</a>@endsection
@section('content')
    @if (!$order)
        @include('portal.cases.order-picker', ['pickerRoute' => 'complaints.create'])
    @else
        <div class="case-identity"><strong
                class="logistics-code">{{ $order->tracking_code }}</strong><span>{{ $order->recipient_name }}</span><a
                href="{{ route('complaints.create') }}">Đổi vận đơn</a></div>
        <form method="post" enctype="multipart/form-data" action="{{ route('complaints.store', $order) }}"
            class="narrow-form mt-4">
            @csrf<input type="hidden" name="client_token"
                value="{{ old('client_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row g-3">
                <div class="col-12"><x-field name="title" label="Tiêu đề" minlength="5" maxlength="180" required /></div>
                <div class="col-12"><x-field name="type" label="Loại khiếu nại" :options="\App\Models\Complaint::TYPES" required /></div>
                <div class="col-12"><x-field name="description" label="Nội dung và yêu cầu xử lý" type="textarea"
                        rows="5" minlength="10" maxlength="5000" required /></div>
                <div class="col-12"><x-field name="evidence" type="file" label="Tệp đính kèm"
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        hint="JPG, PNG, WebP hoặc PDF, tối đa 5 MB." /></div>
            </div>
            <button class="btn btn-primary mt-4"><i data-lucide="send"></i> Gửi khiếu nại</button>
        </form>
    @endif
@endsection
