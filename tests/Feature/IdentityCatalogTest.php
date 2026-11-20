<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeeCalculator;
use App\Services\FeeVersionService;
use App\Services\ShopService;
use Carbon\Carbon;
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
            'name' => 'Nhân viên vận hành mới', 'email' => 'new-staff@test.local', 'phone' => '0987000000', 'password' => 'NewPassword!2026', 'password_confirmation' => 'NewPassword!2026',
            'status' => 'active', 'role_ids' => [Role::where('name', 'operations')->value('id')], 'warehouse_ids' => [$warehouse->id], 'reason' => 'Bổ sung nhân sự vận hành',
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
            'role_ids' => [Role::where('name', 'operations')->value('id')], 'reason' => 'Thử khóa quản trị cuối',
        ])->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_registration_creates_pending_shop_and_default_pickup(): void
    {
        $this->post('/dang-ky', ['account_type' => 'business', 'name' => 'Shop mới', 'shop_name' => 'Tên cửa hàng mới', 'email' => 'register@test.local', 'phone' => '0988111222',
            'password' => 'RegisterPass!2026', 'password_confirmation' => 'RegisterPass!2026', 'area_id' => Area::where('code', 'HN')->value('id'), 'address' => '10 Đường kiểm thử',
        ])->assertRedirect(route('shop.profile'));
        $user = User::where('email', 'register@test.local')->firstOrFail();
        $this->assertSame('pending', $user->shop->status);
        $this->assertSame('business', $user->shop->account_type);
        $this->assertTrue($user->shop->addresses->first()->is_default);
        $this->assertTrue($user->hasRole('shop'));
    }

    public function test_registration_lists_only_active_warehouses_in_active_areas(): void
    {
        $first = $this->warehouse();
        $first->update(['name' => 'Kho trung tâm', 'address' => '10 Đường trung tâm']);
        $second = $this->warehouse();
        $closed = $this->warehouse();
        $closed->update(['is_active' => false]);
        $inactiveAreaWarehouse = $this->warehouse('HP');
        $inactiveAreaWarehouse->area->update(['is_active' => false]);

        $this->get(route('register'))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->count() === 2
                && $warehouses->pluck('code')->diff([$first->code, $second->code])->isEmpty())
            ->assertSee('Kho trung tâm')->assertSee('10 Đường trung tâm')
            ->assertDontSee($closed->code)->assertDontSee($inactiveAreaWarehouse->code)
            ->assertSee('Chưa chọn tỉnh/thành.');
    }

    public function test_registration_restores_warehouse_information_after_validation_failure(): void
    {
        $warehouse = $this->warehouse();
        $this->from(route('register'))->post(route('register.store'), ['area_id' => $warehouse->area_id])
            ->assertRedirect(route('register'))->assertSessionHasErrors('name');

        $response = $this->get(route('register'))->assertOk()->assertSee('Hà Nội: 1 kho đang hoạt động.');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $items = (new \DOMXPath($document))->query('//*[@data-warehouse-area and not(@hidden)]');
        $this->assertCount(1, $items);
        $this->assertStringContainsString($warehouse->code, $items->item(0)->textContent);

        $this->withSession(['_old_input' => ['area_id' => Area::where('code', 'HT')->value('id')]])
            ->get(route('register'))->assertOk()->assertSee('Chưa có kho tiếp nhận đang hoạt động tại Hà Tĩnh.');
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

    public function test_future_fee_publication_can_supersede_an_open_ended_version(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        $admin = $this->person();
        $service = app(FeeVersionService::class);
        $current = $service->save($admin, $this->feeData('2026-01-01T00:00', null));
        $this->actingAs($admin)->post(route('fees.publish', $current), ['reason' => 'Công bố biểu phí hiện hành'])->assertRedirect();

        $data = $this->feeData('2027-01-01T00:00', null);
        $data['rules'][0]['base_fee'] += 1000;
        $next = $service->save($admin, $data);
        $originalRules = $current->rules()->get()->toArray();
        $this->get(route('fees.edit', $next))->assertOk()->assertSee('Biểu phí được thay thế');
        $this->actingAs($admin)->post(route('fees.publish', $next), [
            'reason' => 'Thay biểu phí từ năm sau', 'replaces_version_id' => $current->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('published', $next->fresh()->status);
        $this->assertSame('2027-01-01 00:00:00', $current->fresh()->active_until->format('Y-m-d H:i:s'));
        $this->assertSame('published', $current->fresh()->status);
        $this->assertSame($originalRules, $current->rules()->get()->toArray());
        $audit = AuditLog::where('event', 'fee.superseded')->sole();
        $this->assertNull($audit->old_values['active_until']);
        $this->assertSame('2027-01-01 00:00:00', $audit->new_values['active_until']);
        $area = Area::where('code', 'HN')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-12-31 23:59:59'));
        $this->assertSame($current->id, app(FeeCalculator::class)->calculate($area, $area, 500, 'standard')['version_id']);
        $this->travelTo(Carbon::parse('2027-01-01 00:00:00'));
        $this->assertSame($next->id, app(FeeCalculator::class)->calculate($area, $area, 500, 'standard')['version_id']);
        $this->travelBack();
    }

    public function test_fee_replacement_rejects_backdating_invalid_basis_and_other_overlaps(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        $admin = $this->person();
        $service = app(FeeVersionService::class);
        $current = $service->save($admin, $this->feeData('2026-01-01T00:00', '2028-01-01T00:00'));
        $service->publish($admin, $current, 'Publish current tariff');
        $scheduled = $service->save($admin, $this->feeData('2028-01-01T00:00', null));
        $service->publish($admin, $scheduled, 'Publish scheduled tariff');
        $next = $service->save($admin, $this->feeData('2027-01-01T00:00', null));
        $this->actingAs($admin)->post(route('fees.publish', $next), ['reason' => 'Overlap must remain blocked', 'replaces_version_id' => $current->id])
            ->assertSessionHasErrors('active_from');
        $this->assertSame('2028-01-01', $current->fresh()->active_until->toDateString());
        $this->post(route('fees.publish', $next), ['reason' => 'Cannot replace self', 'replaces_version_id' => $next->id])
            ->assertSessionHasErrors('replaces_version_id');
        $this->post(route('fees.publish', $next), ['reason' => 'Cannot replace later tariff', 'replaces_version_id' => $scheduled->id])
            ->assertSessionHasErrors('replaces_version_id');
        $past = $service->save($admin, $this->feeData('2026-02-01T00:00', '2026-03-01T00:00'));
        $this->post(route('fees.publish', $past), ['reason' => 'Cannot change past coverage', 'replaces_version_id' => $current->id])
            ->assertSessionHasErrors('replaces_version_id');
        $this->actingAs($this->person('operations'))->post(route('fees.publish', $next), ['reason' => 'Staff cannot publish', 'replaces_version_id' => $current->id])->assertForbidden();
        $this->assertSame(0, AuditLog::where('event', 'fee.superseded')->count());
    }

    public function test_fee_replacement_rolls_back_both_versions_if_audit_fails(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));
        $admin = $this->person();
        $service = app(FeeVersionService::class);
        $current = $service->save($admin, $this->feeData('2026-01-01T00:00', null));
        $service->publish($admin, $current, 'Publish current tariff');
        $next = $service->save($admin, $this->feeData('2027-01-01T00:00', null));
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(FeeVersionService::class)->publish($admin, $next, 'Replace tariff with audit', $current->id);
            $this->fail('Expected audit error');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertNull($current->fresh()->active_until);
        $this->assertSame('draft', $next->fresh()->status);
    }

    public function test_fee_editor_recovers_empty_rows_and_keeps_error_indexes_after_deleting_rows(): void
    {
        $this->actingAs($this->person());
        $data = $this->feeData();
        $data['rules'] = array_combine(range(10, 15), $data['rules']);
        $data['rules'][10]['max_cod'] = 100000001;
        $this->from(route('fees.create'))->post(route('fees.store'), $data)
            ->assertRedirect(route('fees.create'))->assertSessionHasErrors('rules.0.max_cod');
        $this->get(route('fees.create'))->assertOk()->assertSee('id="fee-rule-0-max_cod-error"', false);

        $data['rules'] = [];
        $this->post(route('fees.store'), $data)->assertSessionHasErrors('rules');
        $this->get(route('fees.create'))->assertOk()->assertSee('id="fee-rule-template"', false);
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
