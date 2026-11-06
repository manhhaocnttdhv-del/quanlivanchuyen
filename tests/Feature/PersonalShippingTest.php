<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\FeeVersionService;
use App\Services\ShopService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class PersonalShippingTest extends DatabaseTestCase
{
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'individual', 'name' => 'Nguyễn Minh An', 'email' => 'individual@test.local', 'phone' => '0988111444',
            'password' => 'IndividualPass!2026', 'password_confirmation' => 'IndividualPass!2026',
            'area_id' => Area::where('code', 'HN')->value('id'), 'address' => '25 Đường người gửi cá nhân',
        ], $overrides);
    }

    public function test_individual_registration_needs_no_shop_name_and_creates_pending_sender_with_audit(): void
    {
        $this->post(route('register.store'), $this->registration([
            'shop_name' => ['unexpected' => 'ignored'], 'status' => 'approved', 'role_ids' => [1], 'account_type_label' => 'Admin',
        ]))->assertRedirect(route('shop.profile'))->assertSessionHasNoErrors();

        $user = User::where('email', 'individual@test.local')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->auth_version, session('auth_version'));
        $this->assertTrue(Hash::check('IndividualPass!2026', $user->password));
        $this->assertSame(['shop'], $user->roles->pluck('name')->all());
        $this->assertSame('individual', $user->shop->account_type);
        $this->assertSame('Nguyễn Minh An', $user->shop->name);
        $this->assertSame('pending', $user->shop->status);
        $this->assertNull($user->shop->reviewed_by);
        $this->assertCount(1, $user->shop->addresses);
        $this->assertTrue($user->shop->addresses->sole()->is_default);
        $this->assertSame($user->name, $user->shop->addresses->sole()->contact_name);
        $log = AuditLog::where('event', 'shop.registered')->firstOrFail();
        $this->assertSame('individual', $log->new_values['account_type']);
        $this->assertStringNotContainsString('IndividualPass', json_encode($log));
        $this->get(route('shop.profile'))->assertOk()->assertSee('Người gửi &amp; địa chỉ', false)
            ->assertSee('Tên người gửi')->assertDontSee('Tên cửa hàng');
        $this->get(route('dashboard'))->assertOk()->assertSee('Thông tin gửi hàng');
        $this->get(route('orders.create'))->assertOk()->assertSee('Người gửi trả phí');
        $this->get(route('users.index'))->assertForbidden();
    }

    public function test_registration_requires_a_supported_sender_type_and_business_name(): void
    {
        foreach (['admin', '', ['business']] as $type) {
            $this->postJson(route('register.store'), $this->registration(['account_type' => $type]))
                ->assertUnprocessable()->assertJsonValidationErrors('account_type');
        }
        $missingType = $this->registration();
        unset($missingType['account_type']);
        $this->postJson(route('register.store'), $missingType)->assertUnprocessable()->assertJsonValidationErrors('account_type');
        $this->postJson(route('register.store'), $this->registration(['account_type' => 'business']))
            ->assertUnprocessable()->assertJsonValidationErrors('shop_name');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('shops', 0);
    }

    public function test_business_selection_and_shop_name_survive_validation_failure(): void
    {
        $this->from(route('register'))->post(route('register.store'), $this->registration([
            'account_type' => 'business', 'shop_name' => 'Cửa hàng Minh An', 'email' => 'invalid',
        ]))->assertRedirect(route('register'))->assertSessionHasErrors('email');

        $response = $this->get(route('register'))->assertOk()->assertSee('Cửa hàng Minh An');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertCount(1, $xpath->query('//input[@name="account_type" and @value="business" and @checked]'));
        $this->assertCount(1, $xpath->query('//*[@data-business-field and not(@hidden)]//input[@name="shop_name" and @required and not(@disabled)]'));
    }

    public function test_individual_can_save_draft_and_submit_only_after_profile_approval_with_own_data_scope(): void
    {
        $admin = $this->person();
        $warehouse = $this->warehouse();
        $this->warehouse('HCM');
        $fee = app(FeeVersionService::class)->save($admin, $this->feeData());
        app(FeeVersionService::class)->publish($admin, $fee, 'Biểu phí kiểm thử gửi cá nhân');
        $this->post(route('register.store'), $this->registration())->assertRedirect();
        $user = User::where('email', 'individual@test.local')->firstOrFail();
        $this->post(route('orders.store'), [
            'client_token' => (string) Str::uuid(), 'pickup_address_id' => $user->shop->addresses->sole()->id,
            'warehouse_id' => $warehouse->id, 'area_id' => Area::where('code', 'HCM')->value('id'),
            'recipient_name' => 'Trần Thanh Bình', 'recipient_phone' => '0987654321', 'recipient_address' => '20 Đường nhận hàng',
            'weight_grams' => 500, 'parcel_count' => 1, 'service' => 'standard', 'cod_amount' => 0, 'fee_payer' => 'sender',
            'items' => [['name' => 'Quà tặng cá nhân', 'quantity' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->postJson(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('draft', $order->fresh()->status);
        $this->post(route('shops.review', $user->shop), ['status' => 'approved', 'reason' => 'Thử tự duyệt hồ sơ'])
            ->assertForbidden();
        $this->actingAs($admin)->post(route('shops.review', $user->shop), ['status' => 'approved', 'reason' => 'Đã kiểm tra liên hệ và địa chỉ người gửi'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertRedirect()->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('submitted', $order->status);
        $this->assertSame($user->name, $order->sender_snapshot['name']);
        $this->assertSame('0.00', $order->cod_amount);
        $this->assertSame('0.00', $order->recipient_total);
        $other = app(ShopService::class)->register($this->registration([
            'name' => 'Người gửi khác', 'email' => 'another-individual@test.local', 'phone' => '0988111555',
        ]));
        $this->actingAs($other)->get(route('orders.show', $order))->assertForbidden();
        $this->put(route('orders.update', $order), ['revision' => $order->revision])->assertForbidden();
        $this->get(route('orders.index'))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->isEmpty());
    }

    public function test_individual_profile_update_keeps_type_and_cannot_self_approve_or_clear_suspension(): void
    {
        $this->post(route('register.store'), $this->registration())->assertRedirect();
        $user = User::where('email', 'individual@test.local')->firstOrFail();
        $admin = $this->person();
        app(ShopService::class)->review($admin, $user->shop, ['status' => 'approved', 'reason' => 'Đã kiểm tra người gửi']);
        $data = ['name' => 'Tên người gửi đã chỉnh', 'area_id' => $user->shop->area_id, 'address' => 'Địa chỉ hồ sơ đã chỉnh',
            'account_type' => 'business', 'status' => 'approved', 'user_id' => $admin->id];
        $this->put(route('shop.update'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('individual', $user->shop->fresh()->account_type);
        $this->assertSame('pending', $user->shop->fresh()->status);
        $this->assertSame($user->id, $user->shop->fresh()->user_id);
        app(ShopService::class)->review($admin, $user->shop, ['status' => 'suspended', 'reason' => 'Cần xác minh hồ sơ']);
        $this->put(route('shop.update'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('suspended', $user->shop->fresh()->status);
        $this->assertSame('individual', $user->shop->fresh()->account_type);
        $this->actingAs($admin)->get(route('shops.show', $user->shop))->assertOk()->assertSee('Cá nhân');
    }
}
