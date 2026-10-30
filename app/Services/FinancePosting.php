<?php

namespace App\Services;

use App\Models\CashDeposit;
use App\Models\FeeReceipt;
use App\Models\FinanceDocument;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancePosting
{
    public function __construct(private AuditService $audit) {}

    public function run(callable $action): mixed
    {
        return DB::transaction(function () use ($action) {
            // Do an quy mo nho: tuan tu hoa doan ghi tai chinh ngan, van khoa nguon/order.
            // Van hanh khoa shop/order nhu cu, khong phai cho khoa tai chinh khi doc trang.
            DB::table('business_locks')->where('name', 'finance-posting')->lockForUpdate()->firstOrFail();

            return $action();
        }, 3);
    }

    public function orders(array $ids)
    {
        $ids = array_unique($ids);
        $shopIds = Order::whereIn('id', $ids)->pluck('shop_id')->unique()->sort()->values();
        Shop::whereIn('id', $shopIds)->orderBy('id')->lockForUpdate()->get();

        return Order::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function ensure(bool $value, string $message, string $field = 'reason'): void
    {
        if (! $value) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function hash(array $data, ?array $proof = null): string
    {
        unset($data['evidence']);
        ksort($data);

        return hash('sha256', json_encode([$data, $proof['content_sha256'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function replay(string $model, User $actor, array $data, string $hash): ?FinanceDocument
    {
        $existing = $model::where('created_by', $actor->id)->where('client_token', $data['client_token'])->lockForUpdate()->first();
        if ($existing) {
            $this->ensure(hash_equals($existing->request_hash, $hash), 'Mã yêu cầu đã dùng với nội dung khác.', 'client_token');
        }

        return $existing;
    }

    public function identity(string $prefix, User $actor, array $data, string $hash): array
    {
        return ['code' => $prefix.Str::upper((string) Str::ulid()), 'created_by' => $actor->id, 'client_token' => $data['client_token'], 'request_hash' => $hash, 'reason' => $data['reason']];
    }

    public function referenceAvailable(string $reference): void
    {
        // Goi ben trong run(): khoa chung ngan cung giao dich bi ghi o hai loai phieu.
        foreach ([CashDeposit::class, Payment::class, FeeReceipt::class] as $model) {
            $this->ensure(! $model::where('transaction_reference', $reference)->exists(), 'Mã giao dịch đã được ghi trên chứng từ tài chính.', 'transaction_reference');
        }
    }

    public function revision(FinanceDocument $document, array $data): void
    {
        $this->ensure($document->revision === (int) $data['revision'], 'Chứng từ đã thay đổi. Tải lại trang.', 'revision');
    }

    public function event(User $actor, FinanceDocument $document, string $event, string $reason, array $payload = []): void
    {
        $payload += $document->only(['status', 'revision', 'line_version', 'shop_bank_account_id', 'requested_amount', 'received_amount', 'payable_amount', 'debt_amount']);
        $document->events()->create(['document_type' => $document->getTable(), 'created_by' => $actor->id, 'event' => $event, 'reason' => $reason, 'payload' => $payload]);
        $this->audit->record($actor, $document, 'finance.'.$event, [], $document->toArray() + $payload, $reason);
    }
}
