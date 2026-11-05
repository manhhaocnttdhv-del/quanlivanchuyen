<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CatalogService;
use App\Services\IdentityService;
use App\Services\OrderService;
use App\Services\ShopService;
use Database\Seeders\DemoShippingSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class DemoShippingSeederTest extends DatabaseTestCase
{
    private ?string $previousPassword = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->previousPassword = Env::get('DEMO_PASSWORD');
        Env::getRepository()->set('DEMO_PASSWORD', 'ShippingTestPassword!2026');
    }

    protected function tearDown(): void
    {
        if ($this->previousPassword === null) {
            Env::getRepository()->clear('DEMO_PASSWORD');
        } else {
            Env::getRepository()->set('DEMO_PASSWORD', $this->previousPassword);
        }
        parent::tearDown();
    }

    public function test_complete_demo_has_warehouses_pickups_orders_scopes_and_real_histories(): void
    {
        $this->seed(DemoShippingSeeder::class);
        $this->assertDatabaseCount('users', 8);
        $this->assertDatabaseCount('shops', 2);
        $this->assertDatabaseCount('warehouses', 6);
        $this->assertDatabaseCount('warehouse_locations', 12);
        $this->assertDatabaseCount('orders', 21);
        $this->assertDatabaseCount('stocktakes', 1);
        $this->assertDatabaseCount('delivery_tasks', 8);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $this->assertSame(3, $owner->shop->addresses()->count());
        $this->assertSame(1, $owner->shop->addresses()->where('is_default', true)->count());
        foreach (['admin', 'operations', 'shipper'] as $role) {
            $user = User::where('email', $role.'@guiviet.test')->firstOrFail();
            $this->assertSame(6, $user->warehouses()->count());
            $this->assertTrue(Hash::check('ShippingTestPassword!2026', $user->password));
        }
        $branches = [
            'hn' => ['KHO-HN', 'KHO-HN-LB'],
            'hcm' => ['KHO-HCM', 'KHO-HCM-TD'],
            'dn' => ['KHO-DN', 'KHO-DN-LC'],
        ];
        $operators = User::with(['roles', 'warehouses'])->where('email', 'like', 'operations.%@guiviet.test')->get()->keyBy('email');
        $this->assertCount(3, $operators);
        foreach ($branches as $code => $warehouseCodes) {
            $operator = $operators->get('operations.'.$code.'@guiviet.test');
            $this->assertNotNull($operator);
            $this->assertSame(['operations'], $operator->roles->pluck('name')->all());
            $this->assertEqualsCanonicalizing($warehouseCodes, $operator->warehouses->pluck('code')->all());
            $this->assertTrue(Hash::check('ShippingTestPassword!2026', $operator->password));
            $this->assertDatabaseHas('audit_logs', ['subject_type' => User::class, 'subject_id' => $operator->id, 'event' => 'user.saved']);
        }
        $orders = Order::with(['warehouse.area', 'pickupAddress', 'histories'])->where('client_token', 'like', '20261105-0002-4000-8000-%')->get();
        $this->assertCount(6, $orders);
        $this->assertSame(6, $orders->pluck('warehouse_id')->unique()->count());
        $this->assertEqualsCanonicalizing(['HN', 'HCM', 'DN'], $orders->pluck('warehouse.area.code')->unique()->all());
        foreach ($orders as $order) {
            $this->assertSame($order->pickupAddress->area_id, $order->warehouse->area_id);
            $this->assertCount(['draft' => 1, 'submitted' => 2, 'approved' => 3][$order->status], $order->histories);
            $this->assertDatabaseHas('audit_logs', ['subject_type' => Order::class, 'subject_id' => $order->id, 'event' => 'order.created']);
            $this->assertSame($order->warehouse_id, $order->histories->first()->after_values['warehouse_id']);
        }
        $this->actingAs($owner)->get(route('orders.create'))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->count() === 6);
        Storage::disk('local')->assertExists('logistics-evidence/demo-warehouse.jpg');
    }

    public function test_reseeding_does_not_duplicate_or_reset_user_changes(): void
    {
        $this->seed(DemoShippingSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $passwords = User::pluck('password', 'id')->all();
        $warehouse = Warehouse::where('code', 'KHO-HN-LB')->firstOrFail();
        $order = Order::where('client_token', '20261105-0002-4000-8000-000000000001')->firstOrFail();
        app(OrderService::class)->saveDraft($owner, array_merge($order->inputData(), [
            'revision' => $order->revision, 'warehouse_id' => $warehouse->id, 'note' => 'Shop đã sửa dữ liệu demo',
        ]), $order);
        $pickup = $owner->shop->addresses()->whereHas('area', fn ($query) => $query->where('code', 'HCM'))->firstOrFail();
        app(ShopService::class)->saveAddress($owner, $owner->shop, array_merge($pickup->only(['contact_name', 'phone', 'area_id', 'address', 'is_active']), ['is_default' => true]), $pickup);
        app(CatalogService::class)->saveWarehouse(User::where('email', 'admin@guiviet.test')->firstOrFail(), ['name' => 'Kho đã chỉnh sửa', 'is_active' => false], $warehouse);
        $operator = User::where('email', 'operations.hn@guiviet.test')->firstOrFail();
        $reassignedWarehouse = Warehouse::where('code', 'KHO-DN')->firstOrFail();
        app(IdentityService::class)->save(User::where('email', 'admin@guiviet.test')->firstOrFail(), [
            'name' => 'Nhân viên đã chuyển kho', 'email' => $operator->email, 'phone' => $operator->phone,
            'status' => 'inactive', 'role_ids' => [Role::where('name', 'shipper')->value('id')],
            'warehouse_ids' => [$reassignedWarehouse->id], 'reason' => 'Admin chuyển phạm vi và tạm khóa tài khoản',
        ], $operator);
        $counts = $this->counts();
        Env::getRepository()->set('DEMO_PASSWORD', 'AnotherShippingPassword!2026');

        $this->seed(DemoShippingSeeder::class);

        $this->assertSame($counts, $this->counts());
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertSame('Kho đã chỉnh sửa', $warehouse->fresh()->name);
        $this->assertFalse($warehouse->fresh()->is_active);
        $this->assertTrue($pickup->fresh()->is_default);
        $this->assertSame(1, $owner->shop->addresses()->where('is_default', true)->count());
        $this->assertSame($warehouse->id, $order->fresh()->warehouse_id);
        $this->assertSame('Shop đã sửa dữ liệu demo', $order->fresh()->note);
        $this->assertSame(2, $order->histories()->count());
        $this->assertSame('inactive', $operator->fresh()->status);
        $this->assertSame('Nhân viên đã chuyển kho', $operator->fresh()->name);
        $this->assertSame(['shipper'], $operator->fresh()->roles->pluck('name')->all());
        $this->assertSame([$reassignedWarehouse->id], $operator->warehouses()->pluck('warehouses.id')->all());
    }

    public function test_branch_operators_can_view_review_and_dispatch_both_warehouses_only_in_their_branch(): void
    {
        $this->seed(DemoShippingSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $template = Order::where('client_token', '20261105-0002-4000-8000-000000000001')->firstOrFail();
        $orders = collect();
        foreach (Warehouse::all() as $warehouse) {
            $pickup = $owner->shop->addresses()->where('area_id', $warehouse->area_id)->firstOrFail();
            $order = app(OrderService::class)->saveDraft($owner, array_merge($template->inputData(), [
                'client_token' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id, 'pickup_address_id' => $pickup->id,
            ]));
            app(OrderService::class)->submit($owner, $order, $order->revision);
            $orders->put($warehouse->id, $order->fresh());
        }
        $shipper = User::where('email', 'shipper@guiviet.test')->firstOrFail();
        foreach (User::with('warehouses')->where('email', 'like', 'operations.%@guiviet.test')->get() as $operator) {
            $warehouseIds = $operator->warehouses->pluck('id')->all();
            $ownOrders = $orders->whereIn('warehouse_id', $warehouseIds);
            $foreignOrders = $orders->whereNotIn('warehouse_id', $warehouseIds);
            $this->assertCount(2, $ownOrders);
            $this->actingAs($operator)->get(route('orders.index'))->assertOk()
                ->assertViewHas('orders', fn ($list) => $ownOrders->pluck('id')->diff($list->pluck('id'))->isEmpty()
                    && $list->every(fn ($order) => in_array($order->warehouse_id, $warehouseIds)));
            foreach ($foreignOrders as $foreignOrder) {
                $before = $foreignOrder->fresh()->only(['status', 'revision', 'approved_by', 'assigned_shipper_id']);
                $this->get(route('orders.show', $foreignOrder))->assertForbidden();
                $this->postJson(route('orders.review', $foreignOrder), [
                    'revision' => $foreignOrder->fresh()->revision, 'decision' => 'approved', 'reason' => 'Thử duyệt đơn ngoài chi nhánh',
                ])->assertForbidden();
                $this->get(route('logistics.order', $foreignOrder))->assertForbidden();
                $this->postJson(route('logistics.assign', $foreignOrder), [
                    'revision' => $foreignOrder->fresh()->revision, 'type' => 'pickup',
                    'shipper_id' => $shipper->id, 'reason' => 'Thử phân công ngoài chi nhánh',
                ])->assertForbidden();
                $this->assertSame($before, $foreignOrder->fresh()->only(['status', 'revision', 'approved_by', 'assigned_shipper_id']));
            }
            $this->get(route('users.index'))->assertForbidden();
            $this->get(route('catalog.index'))->assertForbidden();
            $this->get(route('audit.index'))->assertForbidden();
            foreach ($ownOrders as $ownOrder) {
                $this->get(route('orders.show', $ownOrder))->assertOk();
                $this->get(route('logistics.order', $ownOrder))->assertOk();
                $this->post(route('orders.review', $ownOrder), [
                    'revision' => $ownOrder->fresh()->revision, 'decision' => 'approved', 'reason' => 'Duyệt vận đơn thuộc chi nhánh phụ trách',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $this->assertSame($operator->id, $ownOrder->fresh()->approved_by);
                $this->post(route('logistics.assign', $ownOrder), [
                    'revision' => $ownOrder->fresh()->revision, 'type' => 'pickup',
                    'shipper_id' => $shipper->id, 'reason' => 'Phân công lấy hàng trong chi nhánh',
                ])->assertRedirect()->assertSessionHasNoErrors();
                $this->assertDatabaseHas('delivery_tasks', [
                    'order_id' => $ownOrder->id, 'warehouse_id' => $ownOrder->warehouse_id, 'shipper_id' => $shipper->id, 'status' => 'assigned',
                ]);
            }
        }
    }

    public function test_warehouse_filters_and_stocktakes_cannot_expand_operator_scope(): void
    {
        $this->seed(DemoShippingSeeder::class);
        $operator = User::where('email', 'operations.hn@guiviet.test')->firstOrFail();
        $foreignOperator = User::where('email', 'operations.dn@guiviet.test')->firstOrFail();
        $ownWarehouse = $operator->warehouses()->where('code', 'KHO-HN')->firstOrFail();
        $ownWarehouseIds = $operator->warehouses()->pluck('warehouses.id')->all();
        $foreignWarehouseIds = $foreignOperator->warehouses()->pluck('warehouses.id')->all();
        $this->actingAs($foreignOperator)->get(route('logistics.inventory'))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->count() === 2 && $warehouses->keys()->diff($foreignWarehouseIds)->isEmpty());
        $this->get(route('logistics.inventory', ['warehouse_id' => $ownWarehouse->id]))->assertOk()
            ->assertViewHas('items', fn ($items) => $items->isEmpty())
            ->assertViewHas('discrepancies', fn ($items) => $items->isEmpty());
        $this->get(route('logistics.index', ['warehouse_id' => $ownWarehouse->id]))->assertOk()
            ->assertViewHas('tasks', fn ($tasks) => $tasks->isEmpty())
            ->assertViewHas('waiting', fn ($orders) => $orders->isEmpty())
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->count() === 2 && $warehouses->keys()->diff($foreignWarehouseIds)->isEmpty());
        $stocktake = Stocktake::firstOrFail();
        $this->get(route('stocktakes.show', $stocktake))->assertForbidden();
        $this->postJson(route('stocktakes.approve', $stocktake), ['reason' => 'Thử duyệt kiểm kê của kho khác'])->assertForbidden();
        $this->postJson(route('stocktakes.store'), [
            'warehouse_id' => $ownWarehouse->id, 'reason' => 'Thử tạo kiểm kê ngoài phạm vi',
        ])->assertForbidden();
        $this->get(route('stocktakes.index'))->assertOk()
            ->assertViewHas('stocktakes', fn ($items) => $items->isEmpty());
        $this->actingAs($operator)->get(route('logistics.inventory'))->assertOk()
            ->assertViewHas('items', fn ($items) => $items->isNotEmpty() && $items->every(fn ($item) => in_array($item->warehouse_id, $ownWarehouseIds)))
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->count() === 2 && $warehouses->keys()->diff($ownWarehouseIds)->isEmpty());
        $this->get(route('stocktakes.show', $stocktake))->assertOk();
    }

    public function test_missing_or_short_demo_password_is_rejected_before_creating_data(): void
    {
        $counts = $this->counts();
        foreach (['', 'short'] as $password) {
            Env::getRepository()->set('DEMO_PASSWORD', $password);
            try {
                $this->seed(DemoShippingSeeder::class);
                $this->fail('Seeder must require an explicit demo password.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('DEMO_PASSWORD', $exception->getMessage());
            }
            $this->assertSame($counts, $this->counts());
        }
    }

    public function test_demo_seeding_is_blocked_outside_local_and_testing(): void
    {
        $counts = $this->counts();
        $this->app['env'] = 'production';
        try {
            app(DemoShippingSeeder::class)->run();
            $this->fail('Demo seeding must be blocked in production.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('local/testing', $exception->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($counts, $this->counts());
    }

    public function test_failure_rolls_back_the_entire_demo_dataset(): void
    {
        $counts = $this->counts();
        $catalog = $this->mock(CatalogService::class);
        $catalog->shouldReceive('saveWarehouse')->once()->andThrow(new \RuntimeException('Simulated seeding failure'));
        try {
            $this->seed(DemoShippingSeeder::class);
            $this->fail('Seeding must roll back when a dependency fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated seeding failure', $exception->getMessage());
        }
        $this->assertSame($counts, $this->counts());
    }

    private function counts(): array
    {
        return collect(['users', 'shops', 'warehouses', 'warehouse_user', 'warehouse_locations', 'pickup_addresses', 'fee_versions', 'fee_rules', 'orders', 'order_status_histories', 'delivery_tasks', 'stocktakes', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }
}
