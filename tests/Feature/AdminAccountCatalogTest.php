<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\IdentityService;
use Tests\DatabaseTestCase;

class AdminAccountCatalogTest extends DatabaseTestCase
{
    public function test_customer_editor_displays_existing_access_without_editable_role_or_warehouse_inputs(): void
    {
        $customer = $this->shopOwner();
        $warehouse = $this->warehouse();
        $warehouse->update(['is_active' => false]);
        $customer->warehouses()->attach($warehouse);

        $this->actingAs($this->person())->get(route('users.edit', $customer))->assertOk()
            ->assertSee(Role::where('name', 'shop')->value('label'))
            ->assertSee($warehouse->name)
            ->assertDontSee('name="role_ids[]"', false)
            ->assertDontSee('name="warehouse_ids[]"', false);
    }

    public function test_admin_can_edit_lock_and_reopen_customer_with_access_profile_and_audit_preserved(): void
    {
        $admin = $this->person();
        $customer = $this->shopOwner();
        $warehouse = $this->warehouse();
        $customer->warehouses()->attach($warehouse);
        $roles = $customer->roles->modelKeys();
        $shop = $customer->shop->getAttributes();
        $version = $customer->auth_version;

        foreach (['active', 'inactive', 'active'] as $index => $status) {
            $data = $this->accountData($customer, ['name' => 'Customer updated '.$index, 'status' => $status]);
            if ($index === 1) {
                $data['role_ids'] = $roles;
            }
            $this->actingAs($admin)->put(route('users.update', $customer), $data)
                ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

            $saved = $customer->fresh();
            $this->assertSame($data['name'], $saved->name);
            $this->assertSame($status, $saved->status);
            $this->assertSame($roles, $saved->roles->modelKeys());
            $this->assertSame([$warehouse->id], $saved->warehouses->modelKeys());
            $this->assertSame($shop, $saved->shop->getAttributes());
            $this->assertSame($version + $index + 1, $saved->auth_version);
            $log = AuditLog::where('event', 'user.saved')->where('subject_id', $customer->id)->latest('id')->firstOrFail();
            $this->assertSame($admin->id, $log->actor_id);
            $this->assertSame($data['reason'], $log->reason);
            $this->assertSame($roles, $log->old_values['role_ids']);
            $this->assertSame($roles, $log->new_values['role_ids']);
            $this->assertSame([$warehouse->id], $log->new_values['warehouse_ids']);
            $this->assertSame($status, $log->new_values['status']);

            if ($status === 'inactive') {
                $this->actingAs($saved)->withSession(['auth_version' => $version])->get(route('dashboard'))
                    ->assertRedirect(route('login'));
                $this->assertGuest();
            }
        }

        $this->actingAs($customer->fresh())->withSession(['auth_version' => $version])->get(route('dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_customer_updates_reject_new_internal_roles_and_warehouse_scopes(): void
    {
        $customer = $this->shopOwner();
        $warehouse = $this->warehouse();
        $adminRole = Role::where('name', 'admin')->value('id');
        $logCount = AuditLog::count();
        $this->actingAs($this->person());

        foreach ([[$adminRole], [$customer->roles->sole()->id, $adminRole]] as $roles) {
            $this->put(route('users.update', $customer), $this->accountData($customer, ['role_ids' => $roles]))
                ->assertSessionHasErrors('role_ids.*');
        }
        $this->put(route('users.update', $customer), $this->accountData($customer, ['warehouse_ids' => [$warehouse->id]]))
            ->assertSessionHasErrors('warehouse_ids');

        $this->assertSame(['shop'], $customer->fresh()->roles->pluck('name')->all());
        $this->assertSame([], $customer->fresh()->warehouses->modelKeys());
        $this->assertSame($customer->auth_version, $customer->fresh()->auth_version);
        $this->assertSame($logCount, AuditLog::count());
    }

    public function test_identity_service_preserves_customer_access_from_locked_database_state(): void
    {
        $admin = $this->person();
        $customer = $this->shopOwner();
        $customer->load(['roles', 'warehouses']);
        $warehouse = $this->warehouse();
        $warehouse->update(['is_active' => false]);
        $customer->roles()->attach(Role::where('name', 'operations')->value('id'));
        $customer->warehouses()->attach($warehouse);
        $roles = $customer->roles()->pluck('roles.id')->all();

        app(IdentityService::class)->save($admin, $this->accountData($customer, [
            'role_ids' => [Role::where('name', 'admin')->value('id')], 'warehouse_ids' => [],
        ]), $customer);

        $this->assertEqualsCanonicalizing($roles, $customer->fresh()->roles->modelKeys());
        $this->assertSame([$warehouse->id], $customer->fresh()->warehouses->modelKeys());
        $this->assertFalse($customer->fresh()->hasRole('admin'));
        $log = AuditLog::where('event', 'user.saved')->where('subject_id', $customer->id)->firstOrFail();
        $this->assertSame($log->old_values['role_ids'], $log->new_values['role_ids']);
        $this->assertSame($log->old_values['warehouse_ids'], $log->new_values['warehouse_ids']);
    }

    public function test_non_admin_roles_cannot_edit_accounts_or_warehouse_catalog(): void
    {
        $customer = $this->shopOwner();
        $warehouse = $this->warehouse();
        $logCount = AuditLog::count();
        foreach (['operations', 'shipper', 'shop'] as $role) {
            $actor = $this->person($role);
            $actor->warehouses()->attach($warehouse);
            $this->actingAs($actor)->get(route('users.edit', $customer))->assertForbidden();
            $this->put(route('users.update', $customer), $this->accountData($customer, ['status' => 'inactive']))->assertForbidden();
            $this->get(route('warehouses.edit', $warehouse))->assertForbidden();
            $this->put(route('warehouses.update', $warehouse), $this->warehouseData($warehouse, ['is_active' => 0]))->assertForbidden();
        }
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertTrue($warehouse->fresh()->is_active);
        $this->assertSame($logCount, AuditLog::count());
    }

    public function test_admin_cannot_lock_self_even_when_another_admin_exists(): void
    {
        $admin = $this->person();
        $this->person();
        $this->actingAs($admin)->put(route('users.update', $admin), $this->accountData($admin, [
            'status' => 'inactive', 'role_ids' => $admin->roles->modelKeys(),
        ]))->assertSessionHasErrors('status');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_internal_staff_roles_and_scope_can_still_be_updated(): void
    {
        $staff = $this->person('operations');
        $warehouse = $this->warehouse();
        $this->actingAs($this->person())->put(route('users.update', $staff), $this->accountData($staff, [
            'role_ids' => [Role::where('name', 'shipper')->value('id')], 'warehouse_ids' => [$warehouse->id],
        ]))->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $this->assertSame(['shipper'], $staff->fresh()->roles->pluck('name')->all());
        $this->assertSame([$warehouse->id], $staff->fresh()->warehouses->modelKeys());
        $this->assertSame($staff->auth_version + 1, $staff->fresh()->auth_version);
    }

    public function test_duplicate_contact_does_not_change_account_roles_or_version(): void
    {
        $staff = $this->person('operations');
        $other = $this->person('shipper');
        $warehouse = $this->warehouse();
        $staff->warehouses()->attach($warehouse);
        $this->actingAs($this->person())->put(route('users.update', $staff), $this->accountData($staff, [
            'email' => $other->email, 'phone' => $other->phone,
            'role_ids' => $staff->roles->modelKeys(), 'warehouse_ids' => [$warehouse->id],
        ]))->assertSessionHasErrors(['email', 'phone']);
        $this->assertSame($staff->auth_version, $staff->fresh()->auth_version);
        $this->assertSame($staff->email, $staff->fresh()->email);
        $this->assertSame(['operations'], $staff->fresh()->roles->pluck('name')->all());
    }

    public function test_admin_can_edit_and_close_warehouse_in_its_current_inactive_area(): void
    {
        $admin = $this->person();
        $warehouse = $this->warehouse();
        $warehouse->area->update(['is_active' => false]);
        $this->actingAs($admin)->get(route('warehouses.edit', $warehouse))->assertOk()
            ->assertViewHas('areas', fn ($areas) => $areas->has($warehouse->area_id))
            ->assertSee($warehouse->area->name.' (ngừng phục vụ)');
        $this->put(route('warehouses.update', $warehouse), $this->warehouseData($warehouse, [
            'name' => 'Warehouse closed for review', 'is_active' => 0,
        ]))->assertRedirect(route('catalog.index'))->assertSessionHasNoErrors();
        $this->assertFalse($warehouse->fresh()->is_active);
        $this->assertSame('Warehouse closed for review', $warehouse->fresh()->name);
        $this->assertSame($warehouse->area_id, $warehouse->fresh()->area_id);
        $log = AuditLog::where('event', 'warehouse.saved')->where('subject_id', $warehouse->id)->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertTrue($log->old_values['is_active']);
        $this->assertSame(0, $log->new_values['is_active']);
    }

    public function test_new_warehouse_and_moves_to_other_inactive_areas_are_rejected(): void
    {
        $warehouse = $this->warehouse();
        $inactive = Area::where('id', '!=', $warehouse->area_id)->firstOrFail();
        $inactive->update(['is_active' => false]);
        $this->actingAs($this->person());
        foreach ([route('warehouses.create'), route('warehouses.edit', $warehouse)] as $url) {
            $this->get($url)->assertOk()->assertViewHas('areas', fn ($areas) => ! $areas->has($inactive->id));
        }
        $data = $this->warehouseData($warehouse, ['area_id' => $inactive->id, 'code' => 'NEW-INACTIVE-AREA']);
        $this->post(route('warehouses.store'), $data)->assertSessionHasErrors('area_id');
        $this->put(route('warehouses.update', $warehouse), $data)->assertSessionHasErrors('area_id');
        $this->assertSame($warehouse->area_id, $warehouse->fresh()->area_id);
        $this->assertDatabaseMissing('warehouses', ['code' => 'NEW-INACTIVE-AREA']);
    }

    public function test_active_area_integer_strings_are_accepted_for_new_warehouses_and_unused_warehouse_moves(): void
    {
        $warehouse = $this->warehouse();
        $active = Area::where('id', '!=', $warehouse->area_id)->where('is_active', true)->firstOrFail();
        $data = $this->warehouseData($warehouse, ['code' => 'NEW-ACTIVE-AREA', 'area_id' => (string) $active->id]);
        $this->actingAs($this->person())->post(route('warehouses.store'), $data)
            ->assertRedirect(route('catalog.index'))->assertSessionHasNoErrors();
        $data['code'] = strtoupper($warehouse->code);
        $this->put(route('warehouses.update', $warehouse), $data)
            ->assertRedirect(route('catalog.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('warehouses', ['code' => 'NEW-ACTIVE-AREA', 'area_id' => $active->id]);
        $this->assertSame($active->id, $warehouse->fresh()->area_id);
    }

    public function test_malformed_area_identifiers_return_validation_errors_without_writes(): void
    {
        $warehouse = $this->warehouse();
        $this->actingAs($this->person());
        $logCount = AuditLog::count();
        foreach ([[$warehouse->area_id], ['id' => $warehouse->area_id], ['id' => [$warehouse->area_id]], 'invalid', '1.5', 1.5, 999999999, null] as $areaId) {
            $data = $this->warehouseData($warehouse, ['code' => 'MALFORMED-AREA', 'area_id' => $areaId]);
            $this->postJson(route('warehouses.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
            $this->putJson(route('warehouses.update', $warehouse), $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
        }
        $this->assertDatabaseMissing('warehouses', ['code' => 'MALFORMED-AREA']);
        $this->assertSame($warehouse->area_id, $warehouse->fresh()->area_id);
        $this->assertSame($logCount, AuditLog::count());
    }

    private function accountData(User $user, array $changes = []): array
    {
        return array_replace([
            'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'status' => 'active', 'reason' => 'Admin regression verification',
        ], $changes);
    }

    private function warehouseData(Warehouse $warehouse, array $changes = []): array
    {
        return array_replace([
            'code' => strtoupper($warehouse->code), 'name' => $warehouse->name, 'address' => $warehouse->address,
            'area_id' => $warehouse->area_id, 'is_active' => 1,
        ], $changes);
    }
}
