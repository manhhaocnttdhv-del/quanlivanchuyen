@extends('layouts.customer')
@section('title', 'Tra cứu vận đơn | Gửi Việt')
@section('content')
    <main id="main-content" class="container py-5">
        <h1 class="h3 mb-4">Tra cứu vận đơn</h1>
        <div class="row"><div class="col-lg-9">
            <form id="tracking-form" method="post" action="{{ route('shipping.track') }}" novalidate>
                @csrf
                <div class="row g-3 align-items-start">
                    <div class="col-md-5"><x-field name="tracking_code" label="Mã đơn hàng" :value="$trackingCode" maxlength="40" autocomplete="off" required /></div>
                    <div class="col-md-4"><x-field name="phone_suffix" label="4 số cuối điện thoại nhận" inputmode="numeric" maxlength="4" autocomplete="off" required /></div>
                    <div class="col-md-3 tool-submit"><button type="submit" class="btn btn-primary w-100"><i data-lucide="search"></i> Tra cứu</button></div>
                </div>
            </form>
            <div id="tracking-result" class="tracking-output d-none mt-4" role="status" aria-live="polite"></div>
        </div></div>
    </main>
@endsection
