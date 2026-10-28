<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DraftShipmentService
{
    public function __construct(private Session $session) {}

    public function all(): array
    {
        return $this->session->get('customer_drafts', []);
    }

    public function find(string $id): array
    {
        // Look up only inside the current session: another visitor cannot access this draft by URL.
        return $this->all()[$id] ?? abort(404);
    }

    public function save(array $data, ?string $id = null): array
    {
        $drafts = $this->all();
        if ($id === null && count($drafts) >= 20) {
            throw ValidationException::withMessages(['goods' => 'Bạn đã có 20 bản nháp. Hãy xóa bản không dùng trước khi tạo thêm.']);
        }

        $existing = $id ? $this->find($id) : null;
        $id ??= (string) Str::uuid();
        $draft = array_merge($data, [
            'id' => $id,
            'code' => $existing['code'] ?? 'NHAP-'.strtoupper(Str::random(10)),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $drafts[$id] = $draft;
        $this->session->put('customer_drafts', $drafts);

        return $draft;
    }

    public function delete(string $id): void
    {
        $this->find($id);
        $drafts = $this->all();
        unset($drafts[$id]);
        $this->session->put('customer_drafts', $drafts);
    }

    public function track(string $code, string $phoneSuffix): array
    {
        foreach ($this->all() as $draft) {
            if ($draft['code'] === $code && hash_equals(substr($draft['recipient_phone'], -4), $phoneSuffix)) {
                // Public responses never include names, addresses, COD or full phone numbers.
                return ['found' => true, 'code' => $code, 'message' => 'Bản nháp đã lưu, chưa gửi vận chuyển. Bạn có thể tiếp tục chỉnh sửa trong Đơn của tôi.'];
            }
        }

        return ['found' => false, 'message' => 'Chưa tìm thấy đơn phù hợp. Hãy kiểm tra mã đơn và 4 số cuối điện thoại người nhận.'];
    }
}
