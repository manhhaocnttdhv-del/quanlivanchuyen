<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DemoShippingSeeder;
use Database\Seeders\DemoWarehouseAccountsSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

class CustomerWarehouseAccessTest extends DatabaseTestCase
{
    public function test_guest_can_find_registration_and_staff_home_has_only_authorized_actions(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('href="'.route('register').'"', false)->assertSee('Đăng nhập')->assertDontSee('Tiếp tục chỉnh sửa');
        $staff = $this->person('operations');
        $this->actingAs($staff)->get(route('home'))->assertOk()->assertSee('href="'.route('logistics.index').'"', false)
            ->assertDontSee('href="'.route('orders.create').'"', false)->assertDontSee('href="'.route('orders.index').'"', false);
    }

    public function test_login_default_destination_follows_role_and_keeps_authorized_intended_page(): void
    {
        $users = [$this->person(), $this->person('operations'), $this->person('shipper'), $this->shopOwner(), $this->shopOwner(false)];
        $destinations = ['dashboard', 'logistics.index', 'delivery.mine', 'orders.index', 'shop.profile'];
        foreach ($users as $index => $user) {
            $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'TestPassword!2026'])->assertRedirect(route($destinations[$index]));
            $this->post(route('logout'));
        }
        $this->withSession(['url.intended' => route('orders.create')])->post(route('login.store'), ['identity' => $users[1]->email, 'password' => 'TestPassword!2026'])->assertRedirect(route('logistics.index'));
        $this->post(route('logout'));
        $this->withSession(['url.intended' => route('orders.create')])->post(route('login.store'), ['identity' => $users[3]->email, 'password' => 'TestPassword!2026'])->assertRedirect(route('orders.create'));
    }

    public function test_internal_operations_and_shipper_require_warehouse_scope(): void
    {
        $admin = $this->person();
        foreach (['operations', 'shipper'] as $index => $role) {
            $this->actingAs($admin)->post(route('users.store'), ['name' => 'Nhân viên chưa có kho', 'email' => $role.'@test.local',
                'phone' => '098777000'.$index, 'password' => 'TestPassword!2026', 'password_confirmation' => 'TestPassword!2026',
                'status' => 'active', 'role_ids' => [Role::where('name', $role)->value('id')], 'reason' => 'Tạo nhân sự thử nghiệm'])
                ->assertSessionHasErrors('warehouse_ids');
            $this->assertDatabaseMissing('users', ['email' => $role.'@test.local']);
        }
    }

    public function test_demo_warehouse_accounts_are_separate_scoped_and_seed_does_not_reset_existing_credentials(): void
    {
        Storage::fake('local');
        $previous = Env::get('DEMO_PASSWORD');
        Env::getRepository()->set('DEMO_PASSWORD', 'WarehouseTest!2026');
        try {
            $this->seed(DemoShippingSeeder::class);
            $oldCount = User::count();
            $this->seed(DemoWarehouseAccountsSeeder::class);
            $this->assertSame($oldCount + 12, User::count());
            foreach (Warehouse::all() as $warehouse) {
                foreach (['operations', 'shipper'] as $role) {
                    $user = User::where('email', $role.'.'.strtolower($warehouse->code).'@guiviet.test')->sole();
                    $this->assertSame([$warehouse->id], $user->warehouses->modelKeys());
                    $this->assertSame([$role], $user->roles->pluck('name')->all());
                    $this->assertTrue(Hash::check('WarehouseTest!2026', $user->password));
                }
            }
            $user = User::where('email', 'operations.kho-hn@guiviet.test')->sole();
            $user->password = 'ChangedPassword!2026';
            $user->save();
            $user->warehouses()->detach();
            $this->seed(DemoWarehouseAccountsSeeder::class);
            $this->assertTrue(Hash::check('ChangedPassword!2026', $user->fresh()->password));
            $this->assertSame(0, $user->warehouses()->count());
            $this->assertSame($oldCount + 12, User::count());
        } finally {
            $previous === null ? Env::getRepository()->clear('DEMO_PASSWORD') : Env::getRepository()->set('DEMO_PASSWORD', $previous);
        }
    }
}
