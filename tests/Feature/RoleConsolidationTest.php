<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoCaseSeeder;
use Database\Seeders\DemoDeliverySeeder;
use Database\Seeders\DemoFinanceSeeder;
use Database\Seeders\DemoLogisticsSeeder;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\DemoReportSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

class RoleConsolidationTest extends DatabaseTestCase
{
    public function test_operations_can_review_shops_but_cannot_manage_users_or_verify_bank_accounts(): void
    {
        $operator = $this->person('operations');
        $owner = $this->shopOwner(false);

        $this->assertSame(['admin', 'operations', 'shipper', 'shop'], Role::orderBy('name')->pluck('name')->all());
        $this->actingAs($operator)->get(route('shops.index'))->assertOk();
        $this->post(route('shops.review', $owner->shop), ['status' => 'approved', 'reason' => 'Đã kiểm tra hồ sơ cửa hàng'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $owner->shop->fresh()->status);
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $owner->shop->bankAccounts()->create(['version' => 1, 'bank_name' => 'Ngân hàng mẫu', 'holder_name' => 'CHU SHOP', 'account_number' => '1234567890', 'last_four' => '7890']);
        $bank = $owner->shop->bankAccounts()->firstOrFail();
        $owner->shop->update(['current_bank_account_id' => $bank->id]);
        $this->post(route('banks.verify', $bank), ['reason' => 'Thử xác minh ngoài quyền'])->assertForbidden();
        $this->assertNull($bank->fresh()->verified_at);

        $owner->roles()->attach(Role::where('name', 'operations')->value('id'));
        $this->actingAs($owner->fresh())->post(route('shops.review', $owner->shop), ['status' => 'rejected', 'reason' => 'Thử tự duyệt hồ sơ'])->assertForbidden();
    }

    public function test_staff_form_lists_only_three_internal_roles_and_rejects_retired_or_shop_roles(): void
    {
        $admin = $this->person();
        $this->actingAs($admin)->get(route('users.create'))->assertOk()
            ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->sort()->values()->all() === Role::INTERNAL_NAMES);

        $retired = Role::create(['name' => 'accountant', 'label' => 'Vai trò cũ']);
        foreach ([$retired->id, Role::where('name', 'shop')->value('id')] as $roleId) {
            $this->post(route('users.store'), [
                'name' => 'Tài khoản mới', 'email' => 'invalid-role@test.local', 'phone' => '0987000011',
                'password' => 'TestPassword!2026', 'password_confirmation' => 'TestPassword!2026',
                'status' => 'active', 'role_ids' => [$roleId], 'reason' => 'Thử gán vai trò không hỗ trợ',
            ])->assertSessionHasErrors('role_ids.0');
        }
        $this->assertDatabaseMissing('users', ['email' => 'invalid-role@test.local']);
    }

    public function test_migration_merges_overlapping_legacy_roles_preserves_warehouse_scope_and_revokes_sessions(): void
    {
        $admin = $this->person();
        $legacy = [];
        foreach (['dispatcher', 'warehouse', 'support', 'accountant'] as $name) {
            $legacy[$name] = Role::create(['name' => $name, 'label' => $name]);
        }
        $legacy['warehouse']->permissions()->attach(DB::table('permissions')->where('name', 'logistics.warehouse')->value('id'));
        $legacy['support']->permissions()->attach(DB::table('permissions')->where('name', 'shops.review')->value('id'));
        $operator = User::factory()->create(['password' => 'ExistingPassword!2026'])->refresh();
        $operator->roles()->attach([$legacy['dispatcher']->id, $legacy['warehouse']->id, $legacy['support']->id]);
        $depot = $this->warehouse();
        $operator->warehouses()->attach($depot);
        $accountant = User::factory()->create(['password' => 'ExistingPassword!2026'])->refresh();
        $accountant->roles()->attach([$legacy['accountant']->id, $legacy['support']->id]);
        $shipper = $this->person('shipper');
        $owner = $this->shopOwner(false);
        $shopId = $owner->shop->id;
        $logCount = AuditLog::count();

        $migration = require database_path('migrations/2026_11_05_100000_consolidate_staff_roles.php');
        $migration->up();

        $this->assertSame(['admin', 'operations', 'shipper', 'shop'], Role::orderBy('name')->pluck('name')->all());
        $this->assertSame(['operations'], $operator->fresh()->roles->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['admin', 'operations'], $accountant->fresh()->roles->pluck('name')->all());
        $this->assertSame([$depot->id], $operator->fresh()->warehouses->modelKeys());
        $this->assertTrue($operator->fresh()->hasPermission('logistics.warehouse'));
        $this->assertTrue($accountant->fresh()->hasPermission('banks.verify'));
        $this->assertSame(2, $operator->fresh()->auth_version);
        $this->assertSame(2, $accountant->fresh()->auth_version);
        $this->assertSame(2, $admin->fresh()->auth_version);
        $this->assertSame(1, $shipper->fresh()->auth_version);
        $this->assertSame($shopId, $owner->fresh()->shop->id);
        $this->assertSame('pending', $owner->fresh()->shop->status);
        $this->assertTrue(Hash::check('ExistingPassword!2026', $operator->fresh()->password));
        $log = AuditLog::where('subject_id', $operator->id)->where('event', 'user.roles_consolidated')->firstOrFail();
        $this->assertSame(['dispatcher', 'support', 'warehouse'], $log->old_values['roles']);
        $this->assertSame(['operations'], $log->new_values['roles']);
        $this->assertSame($logCount + 3, AuditLog::count());
        $this->actingAs($operator->fresh())->withSession(['auth_version' => 1])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $migration->up();
        $this->seed(CatalogSeeder::class);
        $this->assertSame(2, $operator->fresh()->auth_version);
        $this->assertSame($logCount + 3, AuditLog::count());
        $this->assertSame(4, Role::count());
    }

    public function test_demo_seeders_use_three_internal_accounts_and_reseeding_keeps_existing_passwords(): void
    {
        Storage::fake('local');
        $previous = Env::get('DEMO_PASSWORD');
        Env::getRepository()->set('DEMO_PASSWORD', 'RoleDemoPassword!2026');
        try {
            $this->seed(DemoSeeder::class);
            $staff = User::whereHas('roles', fn ($query) => $query->whereIn('name', Role::INTERNAL_NAMES))->get();
            $this->assertEqualsCanonicalizing(['admin@guiviet.test', 'operations@guiviet.test', 'shipper@guiviet.test'], $staff->pluck('email')->all());
            $operator = $staff->firstWhere('email', 'operations@guiviet.test');
            $this->assertSame(2, $operator->warehouses()->count());

            $this->seed(DemoOrderSeeder::class);
            $this->seed(DemoLogisticsSeeder::class);
            $this->assertDatabaseHas('stocktakes', ['created_by' => $operator->id, 'status' => 'submitted']);
            $this->seed(DemoDeliverySeeder::class);
            $this->seed(DemoCaseSeeder::class);
            $this->assertDatabaseHas('complaints', ['assigned_to' => $operator->id, 'status' => 'in_progress']);
            $this->seed(DemoFinanceSeeder::class);
            $this->assertSame(2, User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->count());
            $this->assertDatabaseHas('reconciliations', ['status' => 'approved']);
            $this->seed(DemoReportSeeder::class);
            $this->assertDatabaseHas('orders', ['client_token' => '00000007-0000-4000-8000-000000000001', 'status' => 'delivered']);

            Env::getRepository()->set('DEMO_PASSWORD', 'AnotherDemoPassword!2026');
            $this->seed(DemoSeeder::class);
            $this->assertTrue(Hash::check('RoleDemoPassword!2026', $operator->fresh()->password));
            $this->assertSame(4, Role::count());
        } finally {
            if ($previous === null) {
                Env::getRepository()->clear('DEMO_PASSWORD');
            } else {
                Env::getRepository()->set('DEMO_PASSWORD', $previous);
            }
        }
    }
}
