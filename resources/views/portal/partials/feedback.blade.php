{{-- Tổng hợp lỗi có điểm focus để đọc bằng bàn phím; x-field vẫn hiển thị lỗi tại từng ô. --}}
@if (session('status') || session('success'))
    <div class="alert alert-success" role="status">
        {{ session('status') ?? session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert" tabindex="-1" data-validation-summary><strong>Vui lòng kiểm tra thông
            tin.</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
