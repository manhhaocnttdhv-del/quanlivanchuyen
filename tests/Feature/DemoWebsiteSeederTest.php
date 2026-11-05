<?php

namespace Tests\Feature;

use App\Models\FeeVersion;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Stocktake;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\LogisticsPolicy;
use App\Services\AuditService;
use App\Services\IdentityService;
use App\Services\LogisticsLedger;
use App\Services\OrderService;
use App\Services\ShopService;
use App\Services\StocktakeService;
use Database\Seeders\DemoWebsiteSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DatabaseTestCase;

class DemoWebsiteSeederTest extends DatabaseTestCase
{
    private ?string $previousPassword = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->previousPassword = Env::get('DEMO_PASSWORD');
        Env::getRepository()->set('DEMO_PASSWORD', 'WebsiteTestPassword!2026');
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

    public function test_full_website_demo_covers_every_branch_and_implemented_workflow(): void
    {
        $this->seed(DemoWebsiteSeeder::class);

        $this->assertDatabaseCount('users', 16);
        $this->assertDatabaseCount('shops', 7);
        $this->assertDatabaseCount('orders', 123);
        $this->assertDatabaseCount('delivery_tasks', 92);
        $this->assertDatabaseCount('stocktakes', 19);
        $this->assertDatabaseCount('fee_versions', 2);
        $this->assertEqualsCanonicalizing(array_keys(Shop::STATUSES), Shop::distinct()->pluck('status')->all());
        $this->assertDatabaseHas('fee_versions', ['name' => 'Biểu phí demo dự thảo', 'status' => 'draft']);
        $orders = Order::with(['pickupAddress', 'warehouse', 'histories'])->where('client_token', 'like', '20261105-0004-4000-8000-%')->get();
        $this->assertCount(102, $orders);
        $this->assertSame(6, $orders->pluck('warehouse_id')->unique()->count());
        $expectedStatuses = [
            'draft' => 'draft', 'submitted' => 'submitted', 'needs_info' => 'needs_info', 'rejected' => 'rejected',
            'cancelled' => 'cancelled', 'change_pending' => 'approved', 'waiting' => 'approved', 'pickup' => 'approved',
            'pickup_failed' => 'approved', 'inbound' => 'picked_up', 'stocked' => 'at_warehouse', 'damaged' => 'at_warehouse',
            'weight_review' => 'at_warehouse', 'outbound' => 'at_warehouse', 'on_route' => 'out_for_delivery',
            'recall' => 'out_for_delivery', 'returning' => 'returning',
        ];
        foreach (Warehouse::all() as $warehouse) {
            $warehouseOrders = $orders->where('warehouse_id', $warehouse->id)->keyBy('note');
            $this->assertCount(17, $warehouseOrders);
            foreach ($expectedStatuses as $scenario => $status) {
                $order = $warehouseOrders->get('Demo toàn web: '.$scenario.' / '.$warehouse->code);
                $this->assertNotNull($order);
                $this->assertSame($status, $order->status);
                $this->assertSame($order->pickupAddress->area_id, $order->warehouse->area_id);
                $this->assertSame($order->revision, $order->histories->first()->revision);
                $this->assertDatabaseHas('audit_logs', ['subject_type' => Order::class, 'subject_id' => $order->id, 'event' => 'order.created']);
            }
            $weight = $warehouseOrders->get('Demo toàn web: weight_review / '.$warehouse->code);
            $this->assertSame(1000, $weight->weight_grams);
            $this->assertSame(1200, (int) $weight->parcels()->sum('actual_weight_grams'));
            $this->assertSame('measured_weight', $weight->pendingChange->type);
            $this->assertSame(1200, $weight->pendingChange->changes['weight_grams']);
            $this->assertTrue($weight->discrepancies()->where('type', 'weight')->where('status', 'open')->exists());
            $recall = $warehouseOrders->get('Demo toàn web: recall / '.$warehouse->code);
            $this->assertTrue($recall->tasks()->where('type', 'delivery')->where('status', 'recall_pending')->exists());
            $return = $warehouseOrders->get('Demo toàn web: returning / '.$warehouse->code);
            $this->assertNotNull($return->return_authorized_by);
            $this->assertSame(2, $return->parcels()->where('holder_type', 'shipper')->count());
            $this->assertTrue($return->tasks()->where('type', 'return')->where('status', 'in_custody')->exists());
        }
        foreach (['hn', 'hcm', 'dn'] as $code) {
            $operator = User::where('email', 'operations.'.$code.'@guiviet.test')->firstOrFail();
            $shipper = User::where('email', 'shipper.'.$code.'@guiviet.test')->firstOrFail();
            $owner = User::where('email', 'shop.'.$code.'@guiviet.test')->firstOrFail();
            $this->assertEqualsCanonicalizing($operator->warehouses->pluck('id')->all(), $shipper->warehouses->pluck('id')->all());
            $this->assertSame(['shipper'], $shipper->roles->pluck('name')->all());
            $this->assertTrue(Hash::check('WebsiteTestPassword!2026', $shipper->password));
            $this->assertTrue(Hash::check('WebsiteTestPassword!2026', $owner->password));
            $this->assertSame(34, Order::where('shop_id', $owner->shop->id)->count());
            $this->assertSame($code !== 'dn', $owner->shop->currentBankAccount->verified_at !== null);
            $this->actingAs($operator)->get(route('logistics.index'))->assertOk()
                ->assertViewHas('tasks', fn ($tasks) => $tasks->isNotEmpty() && $tasks->every(fn ($task) => $operator->warehouses->contains('id', $task->warehouse_id)));
            $this->get(route('logistics.inventory'))->assertOk()
                ->assertViewHas('items', fn ($items) => $items->isNotEmpty())
                ->assertViewHas('discrepancies', fn ($items) => $items->isNotEmpty());
            $this->get(route('stocktakes.index'))->assertOk()
                ->assertViewHas('stocktakes', fn ($items) => $items->count() >= 6 && $items->every(fn ($item) => $operator->warehouses->contains('id', $item->warehouse_id)));
            $this->actingAs($shipper)->get(route('logistics.index'))->assertOk()
                ->assertViewHas('tasks', fn ($tasks) => $tasks->isNotEmpty() && $tasks->every(fn ($task) => $task->shipper_id === $shipper->id));
            $this->actingAs($owner)->get(route('orders.index'))->assertOk()
                ->assertViewHas('orders', fn ($items) => $items->isNotEmpty() && $items->every(fn ($item) => $item->shop_id === $owner->shop->id));
        }
        foreach (Stocktake::where('reason', 'like', 'Demo toàn web:%')->where('status', 'approved')->get() as $stocktake) {
            $this->assertNotSame($stocktake->created_by, $stocktake->approved_by);
            $this->assertTrue($stocktake->discrepancies()->where('type', 'stock_missing')->exists());
            $this->assertFalse($stocktake->discrepancies()->whereHas('order', fn ($query) => $query
                ->where('client_token', 'not like', '20261105-0004-4000-8000-%'))->exists());
        }
        $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
        foreach (['users.index', 'shops.index', 'catalog.index', 'fees.index', 'audit.index', 'dashboard'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        Storage::disk('local')->assertExists('logistics-evidence/demo-warehouse.jpg');
    }

    public function test_reseeding_preserves_processed_orders_bank_history_accounts_and_fee_drafts(): void
    {
        $this->seed(DemoWebsiteSeeder::class);
        $owner = User::where('email', 'shop.hcm@guiviet.test')->firstOrFail();
        $order = Order::where('note', 'Demo toàn web: draft / KHO-HCM')->firstOrFail();
        app(OrderService::class)->saveDraft($owner, array_merge($order->inputData(), [
            'revision' => $order->revision, 'note' => 'Khách đã sửa đơn mẫu', 'cod_amount' => 765000,
        ]), $order);
        $bank = $owner->shop->currentBankAccount;
        app(ShopService::class)->replaceBank($owner, $owner->shop, [
            'bank_name' => 'Ngân hàng khách thay đổi', 'holder_name' => 'CHU SHOP HCM', 'account_number' => '9999999990000',
        ]);
        $shipper = User::where('email', 'shipper.dn@guiviet.test')->firstOrFail();
        $admin = User::where('email', 'admin@guiviet.test')->firstOrFail();
        app(IdentityService::class)->save($admin, [
            'name' => 'Nhân sự đã thay đổi', 'email' => $shipper->email, 'phone' => $shipper->phone,
            'status' => 'inactive', 'role_ids' => [Role::where('name', 'operations')->value('id')],
            'warehouse_ids' => [Warehouse::where('code', 'KHO-HN')->value('id')], 'reason' => 'Admin thay đổi nhân sự demo',
        ], $shipper);
        $fee = FeeVersion::where('name', 'Biểu phí demo dự thảo')->firstOrFail();
        $fee->update(['active_from' => '2029-01-01 00:00:00']);
        $counts = $this->counts();
        $passwords = User::pluck('password', 'id')->all();
        Env::getRepository()->set('DEMO_PASSWORD', 'NewWebsitePassword!2026');

        $this->seed(DemoWebsiteSeeder::class);

        $this->assertSame($counts, $this->counts());
        $this->assertSame($passwords, User::pluck('password', 'id')->all());
        $this->assertSame('Khách đã sửa đơn mẫu', $order->fresh()->note);
        $this->assertSame('765000.00', $order->fresh()->cod_amount);
        $this->assertSame('Ngân hàng khách thay đổi', $owner->shop->fresh()->currentBankAccount->bank_name);
        $this->assertNotNull($bank->fresh()->verified_at);
        $this->assertSame('inactive', $shipper->fresh()->status);
        $this->assertSame(['operations'], $shipper->fresh()->roles->pluck('name')->all());
        $this->assertSame('2029-01-01', $fee->fresh()->active_from->format('Y-m-d'));
    }

    public function test_failure_in_branch_workflow_rolls_back_base_and_website_data(): void
    {
        $counts = $this->counts();
        $stocktakes = \Mockery::mock(StocktakeService::class, [
            app(LogisticsLedger::class), app(LogisticsPolicy::class), app(AuditService::class),
        ])->makePartial();
        $this->instance(StocktakeService::class, $stocktakes);
        $stocktakes->shouldReceive('approve')->once()->andThrow(new \RuntimeException('Simulated website demo failure'));
        try {
            $this->seed(DemoWebsiteSeeder::class);
            $this->fail('Full website seed must roll back on a workflow failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated website demo failure', $exception->getMessage());
        }
        $this->assertSame($counts, $this->counts());
    }

    private function counts(): array
    {
        return collect([
            'users', 'shops', 'shop_bank_accounts', 'warehouses', 'warehouse_user', 'warehouse_locations', 'pickup_addresses',
            'fee_versions', 'fee_rules', 'orders', 'order_items', 'order_status_histories', 'order_change_requests',
            'delivery_tasks', 'task_assignments', 'pickup_attempts', 'warehouse_items', 'handover_documents', 'handover_lines',
            'discrepancies', 'stocktakes', 'stocktake_lines', 'audit_logs',
        ])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }
}
