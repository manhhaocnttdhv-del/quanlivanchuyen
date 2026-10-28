<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\FeeVersionService;
use App\Services\ShopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

class IdentityCatalogTest extends DatabaseTestCase
{
    public function test_login_accepts_email_and_phone_and_logout_invalidates_session(): void
    {
        $user = $this->person();
        foreach ([$user->email, $user->phone] as $identity) {
            $this->post('/dang-nhap', ['identity' => $identity, 'password' => 'TestPassword!2026'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post('/dang-xuat')->assertRedirect(route('home'));
            $this->assertGuest();
        }
    }

    public function test_wrong_credentials_are_generic_and_throttled(): void
    {
        $user = $this->person();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/dang-nhap', ['identity' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('identity');
        }
        $this->post('/dang-nhap', ['identity' => $user->email, 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_locked_users_and_revoked_session_cannot_continue(): void
    {
        $user = $this->person();
        $user->status = 'inactive';
        $user->save();
        $this->post('/dang-nhap', ['identity' => $user->email, 'password' => 'TestPassword!2026'])->assertSessionHasErrors('identity');
        $this->actingAs($user)->get('/tong-quan')->assertRedirect(route('login'));
        $user->status = 'active';
        $user->auth_version = 2;
        $user->save();
        $this->actingAs($user)->withSession(['auth_version' => 1])->get('/tong-quan')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_profile_is_current_user_only_and_password_is_hashed(): void
    {
        $user = $this->person('shop');
        $other = $this->person('shop');
        $this->actingAs($user)->put('/ho-so', ['name' => 'Đã sửa', 'email' => $user->email, 'phone' => $user->phone, 'user_id' => $other->id])->assertRedirect();
        $this->assertSame('Đã sửa', $user->fresh()->name);
        $this->assertNotSame('Đã sửa', $other->fresh()->name);
        $this->put('/mat-khau', ['current_password' => 'wrong', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])->assertSessionHasErrors('current_password');
        $this->put('/mat-khau', ['current_password' => 'TestPassword!2026', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026'])->assertRedirect();
        $this->assertTrue(Hash::check('NewPassword!2026', $user->fresh()->password));
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->assertStringNotContainsString('NewPassword', json_encode(AuditLog::all()));
    }

    public function test_shop_cannot_access_admin_routes(): void
    {
        $shop = $this->shopOwner();
        foreach (['/quan-tri/tai-khoan', '/quan-tri/danh-muc', '/quan-tri/bieu-phi', '/quan-tri/nhat-ky', '/quan-tri/shops'] as $path) {
            $this->actingAs($shop)->get($path)->assertForbidden();
        }
    }

    public function test_admin_can_create_staff_with_role_and_warehouse_scope(): void
    {
        $admin = $this->person();
        $warehouse = $this->warehouse();
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Điều phối mới', 'email' => 'new-staff@test.local', 'phone' => '0987000000', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026',
            'status' => 'active', 'role_ids' => [Role::where('name', 'dispatcher')->value('id')], 'warehouse_ids' => [$warehouse->id], 'reason' => 'Bổ sung nhân sự điều phối',
        ])->assertRedirect(route('users.index'));
        $staff = User::where('email', 'new-staff@test.local')->firstOrFail();
        $this->assertTrue($staff->hasPermission('orders.review'));
        $this->assertFalse($staff->hasPermission('users.manage'));
        $this->assertSame([$warehouse->id], $staff->warehouses->modelKeys());
    }

    public function test_final_admin_cannot_be_locked_or_demoted(): void
    {
        $admin = $this->person();
        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'phone' => $admin->phone, 'status' => 'inactive',
            'role_ids' => [Role::where('name', 'dispatcher')->value('id')], 'reason' => 'Thử khóa quản trị cuối',
        ])->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_registration_creates_pending_shop_and_default_pickup(): void
    {
        $this->post('/dang-ky', ['name' => 'Shop mới', 'shop_name' => 'Tên cửa hàng mới', 'email' => 'register@test.local', 'phone' => '0988111222',
            'password' => 'RegisterPass!2026', 'password_confirmation' => 'RegisterPass!2026', 'area_id' => Area::where('code', 'HN')->value('id'), 'address' => '10 Đường kiểm thử',
        ])->assertRedirect(route('shop.profile'));
        $user = User::where('email', 'register@test.local')->firstOrFail();
        $this->assertSame('pending', $user->shop->status);
        $this->assertTrue($user->shop->addresses->first()->is_default);
        $this->assertTrue($user->hasRole('shop'));
    }

    public function test_bank_replacement_keeps_verified_history_and_requires_fresh_verification(): void
    {
        $user = $this->shopOwner();
        $admin = $this->person();
        $data = ['bank_name' => 'Ngân hàng mẫu', 'holder_name' => 'CHU SHOP', 'account_number' => '1234567890'];
        $this->actingAs($user)->post(route('shop.bank'), $data)->assertRedirect();
        $old = $user->shop->fresh()->currentBankAccount;
        $this->assertNotSame('1234567890', DB::table('shop_bank_accounts')->where('id', $old->id)->value('account_number'));
        $this->post(route('banks.verify', $old), ['reason' => 'Chủ shop tự xác minh'])->assertForbidden();
        $this->actingAs($admin)->post(route('banks.verify', $old), ['reason' => 'Đối chiếu thông tin ngân hàng'])->assertRedirect();
        $this->actingAs($user)->post(route('shop.bank'), array_merge($data, ['account_number' => '9999888877']))->assertRedirect();
        $latest = $user->shop->fresh()->currentBankAccount;
        $this->assertSame(2, $latest->version);
        $this->assertNull($latest->verified_at);
        $this->assertNotNull($old->fresh()->verified_at);
        $this->actingAs($admin)->post(route('banks.verify', $old), ['reason' => 'Xác minh lại tài khoản cũ'])->assertForbidden();
        $this->assertStringNotContainsString('1234567890', json_encode(AuditLog::all()));
        $this->assertStringNotContainsString('9999888877', json_encode(AuditLog::all()));
    }

    public function test_default_pickup_is_unique_and_other_shop_cannot_edit(): void
    {
        $owner = $this->shopOwner();
        $other = $this->shopOwner();
        $data = ['area_id' => Area::where('code', 'HN')->value('id'), 'contact_name' => 'Tên liên hệ', 'phone' => '0988111222', 'address' => 'Địa chỉ thứ hai', 'is_active' => 1, 'is_default' => 1];
        $this->actingAs($owner)->post(route('pickups.store'), $data)->assertRedirect();
        $this->assertSame(1, $owner->shop->addresses()->where('is_default', true)->count());
        $address = $owner->shop->addresses()->latest('id')->first();
        $this->actingAs($other)->put(route('pickups.update', $address), $data)->assertForbidden();
        $this->actingAs($owner)->put(route('pickups.update', $address), array_merge($data, ['is_active' => 0]))->assertRedirect();
        $this->assertFalse($address->fresh()->is_default);
        $this->assertFalse($address->fresh()->is_active);
    }

    public function test_shop_change_requires_reapproval_and_suspension_cannot_be_self_cleared(): void
    {
        $owner = $this->shopOwner();
        $admin = $this->person();
        $data = ['name' => 'Shop đổi tên', 'address' => 'Địa chỉ mới', 'area_id' => Area::where('code', 'HN')->value('id')];
        $this->actingAs($owner)->put(route('shop.update'), $data)->assertRedirect();
        $this->assertSame('pending', $owner->shop->fresh()->status);
        $this->actingAs($admin)->post(route('shops.review', $owner->shop), ['status' => 'suspended', 'reason' => 'Hồ sơ cần rà soát lại'])->assertRedirect();
        $this->actingAs($owner)->put(route('shop.update'), $data)->assertRedirect();
        $this->assertSame('suspended', $owner->shop->fresh()->status);
    }

    public function test_fee_publication_is_immutable_and_rejects_overlaps(): void
    {
        $admin = $this->person();
        $service = app(FeeVersionService::class);
        $first = $service->save($admin, $this->feeData());
        $this->actingAs($admin)->post(route('fees.publish', $first), ['reason' => 'Công bố phiên bản đầu'])->assertRedirect();
        $this->put(route('fees.update', $first), $this->feeData())->assertSessionHasErrors('name');
        $second = $service->save($admin, $this->feeData('2027-01-01T00:00', '2029-01-01T00:00'));
        $this->post(route('fees.publish', $second), ['reason' => 'Thử phiên bản trùng'])->assertSessionHasErrors('active_from');
        $this->assertSame('draft', $second->fresh()->status);
        $adjacent = $service->save($admin, $this->feeData('2028-01-01T00:00', null));
        $this->post(route('fees.publish', $adjacent), ['reason' => 'Công bố kế tiếp'])->assertRedirect();
        $this->assertSame('published', $adjacent->fresh()->status);
    }

    public function test_fee_rules_reject_overlapping_weight_and_extra_keys(): void
    {
        $admin = $this->person();
        $data = $this->feeData();
        $data['rules'][] = $data['rules'][0];
        $this->actingAs($admin)->post(route('fees.store'), $data)->assertSessionHasErrors('rules');
        $data = $this->feeData();
        $data['rules'][0]['fee_version_id'] = 123;
        $this->post(route('fees.store'), $data)->assertSessionHasErrors('rules.0');
    }

    public function test_audit_rolls_back_with_business_change_and_is_append_only(): void
    {
        $admin = $this->person();
        $count = AuditLog::count();
        try {
            DB::transaction(function () use ($admin) {
                app(ShopService::class)->saveAddress($admin, $this->shopOwner()->shop, ['area_id' => Area::first()->id, 'contact_name' => 'Tên thử', 'phone' => '0988111222', 'address' => 'Địa chỉ thử', 'is_active' => true, 'is_default' => true]);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($count, AuditLog::count());
        $owner = $this->shopOwner();
        $log = AuditLog::firstOrFail();
        $this->expectException(\LogicException::class);
        $log->update(['reason' => 'Thử sửa lịch sử']);
    }

    public function test_workspace_pages_render_for_authorized_user(): void
    {
        $admin = $this->person();
        $owner = $this->shopOwner();
        app(ShopService::class)->replaceBank($owner, $owner->shop, ['bank_name' => 'Ngân hàng', 'holder_name' => 'CHU SHOP', 'account_number' => '123456789']);
        $fee = app(FeeVersionService::class)->save($admin, $this->feeData());
        foreach ([route('dashboard'), route('profile.edit'), route('users.index'), route('users.create'), route('users.edit', $admin), route('shops.index'), route('shops.show', $owner->shop), route('catalog.index'), route('areas.create'), route('warehouses.create'), route('fees.index'), route('fees.create'), route('fees.edit', $fee), route('audit.index')] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
        foreach ([route('shop.profile'), route('pickups.create'), route('pickups.edit', $owner->shop->addresses->first())] as $path) {
            $this->actingAs($owner)->get($path)->assertOk();
        }
    }
}
