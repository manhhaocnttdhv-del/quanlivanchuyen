<?php

namespace App\Services;

use App\Models\PickupAddress;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopService
{
    public function __construct(private AuditService $audit) {}

    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create(collect($data)->only(['name', 'email', 'phone', 'password'])->all());
            $user->roles()->attach(Role::where('name', 'shop')->firstOrFail());
            $shop = new Shop(['name' => $data['shop_name'], 'area_id' => $data['area_id'], 'address' => $data['address']]);
            $shop->user()->associate($user);
            $shop->save();
            $shop->addresses()->create(['contact_name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'], 'area_id' => $data['area_id'], 'is_active' => true, 'is_default' => true]);
            $this->audit->record($user, $shop, 'shop.registered', [], ['name' => $shop->name, 'status' => 'pending']);

            return $user;
        });
    }

    public function update(User $actor, Shop $shop, array $data): void
    {
        DB::transaction(function () use ($actor, $shop, $data) {
            $shop = Shop::lockForUpdate()->findOrFail($shop->id);
            $before = $shop->only(['name', 'address', 'area_id', 'status']);
            $shop->fill($data);
            // Thông tin định danh đổi phải được duyệt lại; shop bị treo không tự mở khóa.
            if ($shop->status !== 'suspended') {
                $shop->status = 'pending';
            }
            $shop->save();
            $this->audit->record($actor, $shop, 'shop.updated', $before, $shop->only(['name', 'address', 'area_id', 'status']));
        });
    }

    public function review(User $actor, Shop $shop, array $data): void
    {
        DB::transaction(function () use ($actor, $shop, $data) {
            $shop = Shop::lockForUpdate()->findOrFail($shop->id);
            if ($shop->status === $data['status']) {
                throw ValidationException::withMessages(['status' => 'Shop đã ở trạng thái này.']);
            }
            $before = ['status' => $shop->status];
            $shop->status = $data['status'];
            $shop->review_reason = $data['reason'];
            $shop->reviewed_by = $actor->id;
            $shop->approved_at = $data['status'] === 'approved' ? now() : null;
            $shop->save();
            $this->audit->record($actor, $shop, 'shop.reviewed', $before, ['status' => $shop->status], $data['reason']);
        });
    }

    public function replaceBank(User $actor, Shop $shop, array $data): void
    {
        DB::transaction(function () use ($actor, $shop, $data) {
            $shop = Shop::lockForUpdate()->findOrFail($shop->id);
            $bank = $shop->bankAccounts()->create(array_merge($data, [
                'version' => ($shop->bankAccounts()->max('version') ?? 0) + 1,
                'last_four' => substr($data['account_number'], -4),
            ]));
            $shop->current_bank_account_id = $bank->id;
            $shop->save();
            $this->audit->record($actor, $bank, 'bank.replaced', [], ['version' => $bank->version, 'bank_name' => $bank->bank_name, 'last_four' => $bank->last_four]);
        });
    }

    public function verifyBank(User $actor, ShopBankAccount $bank, string $reason): void
    {
        DB::transaction(function () use ($actor, $bank, $reason) {
            $shop = Shop::lockForUpdate()->findOrFail($bank->shop_id);
            $bank = ShopBankAccount::lockForUpdate()->findOrFail($bank->id);
            if ($shop->current_bank_account_id !== $bank->id || $bank->verified_at) {
                throw ValidationException::withMessages(['reason' => 'Phiên bản đã xác minh hoặc không còn hiện hành.']);
            }
            $bank->verified_by = $actor->id;
            $bank->verified_at = now();
            $bank->save();
            $this->audit->record($actor, $bank, 'bank.verified', [], ['verified_at' => now()->toDateTimeString(), 'last_four' => $bank->last_four], $reason);
        });
    }

    public function saveAddress(User $actor, Shop $shop, array $data, ?PickupAddress $address = null): void
    {
        DB::transaction(function () use ($actor, $shop, $data, $address) {
            $shop = Shop::lockForUpdate()->findOrFail($shop->id);
            $address = $address ? $shop->addresses()->lockForUpdate()->findOrFail($address->id) : new PickupAddress;
            $before = $address->only(['address', 'area_id', 'is_active', 'is_default']);
            $data['is_default'] = (bool) $data['is_default'] && (bool) $data['is_active'];
            if ($data['is_default']) {
                $shop->addresses()->where('is_default', true)->update(['is_default' => false]);
            }
            $address->fill($data);
            $address->shop()->associate($shop);
            $address->save();
            $this->audit->record($actor, $address, 'pickup.saved', $before, $address->only(['address', 'area_id', 'is_active', 'is_default']));
        });
    }
}
