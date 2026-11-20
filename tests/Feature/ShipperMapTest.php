<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeliveryTask;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class ShipperMapTest extends DatabaseTestCase
{
    private User $shipper;

    private User $owner;

    private Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        $this->shipper->warehouses()->attach($this->depot);
    }

    private function task(string $type = 'pickup', string $status = 'assigned', ?User $shipper = null, ?Warehouse $warehouse = null): DeliveryTask
    {
        $warehouse ??= $this->depot;
        $shipper ??= $this->shipper;
        $order = Order::create(['shop_id' => $this->owner->shop->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()),
            'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'created_by' => $this->owner->id,
            'warehouse_id' => $warehouse->id, 'status' => 'approved', 'area_id' => $warehouse->area_id,
            'sender_snapshot' => ['name' => 'Người gửi kiểm thử', 'phone' => '0912345678', 'address' => '15 Đinh Tiên Hoàng', 'area' => 'Hà Nội'],
            'recipient_snapshot' => ['area' => 'Hà Nội'], 'recipient_name' => 'Người nhận kiểm thử', 'recipient_phone' => '0987654321',
            'recipient_address' => '20 Tràng Tiền', 'parcel_count' => 2, 'cod_amount' => 500000, 'shipping_fee' => 32000]);

        return $order->tasks()->create(['shipper_id' => $shipper->id, 'warehouse_id' => $warehouse->id,
            'created_by' => $this->owner->id, 'type' => $type, 'status' => $status, 'reason' => 'Fixture bản đồ theo phân công']);
    }

    public function test_only_active_shipper_can_open_map_and_current_scope_is_rechecked(): void
    {
        $task = $this->task();
        $this->get(route('delivery.map'))->assertRedirect(route('login'));
        foreach ([$this->person(), $this->person('operations'), $this->owner] as $user) {
            $this->actingAs($user)->get(route('delivery.map'))->assertForbidden();
        }
        $this->actingAs($this->shipper)->get(route('delivery.map', ['task' => $task->id]))->assertOk();
        $this->shipper->warehouses()->detach();
        $this->get(route('delivery.map'))->assertOk()->assertSee('Chưa có điểm dừng phù hợp')->assertDontSee($task->order->tracking_code);
        $this->get(route('delivery.map', ['task' => $task->id]))->assertNotFound();
        $this->get(route('delivery.mine', ['type' => 'pickup']))->assertDontSee($task->order->tracking_code);
        $this->postJson(route('orders.scan-resolve'), ['code' => $task->order->tracking_code])->assertUnprocessable();
        $this->shipper->status = 'locked';
        $this->shipper->save();
        $this->get(route('delivery.map'))->assertRedirect(route('login'));
    }

    public function test_next_stop_follows_pickup_delivery_return_and_recall_custody(): void
    {
        $this->actingAs($this->shipper);
        foreach ([['pickup', 'assigned', 'Lấy tại người gửi', '15 Đinh Tiên Hoàng'],
            ['pickup', 'in_custody', 'Trả kiện về kho', $this->depot->address],
            ['delivery', 'assigned', 'Đến kho nhận kiện', $this->depot->address],
            ['delivery', 'in_custody', 'Giao người nhận', '20 Tràng Tiền'],
            ['return', 'assigned', 'Đến kho nhận kiện', $this->depot->address],
            ['return', 'in_custody', 'Hoàn người gửi', '15 Đinh Tiên Hoàng'],
            ['delivery', 'recall_pending', 'Trả kiện về kho', $this->depot->address]] as [$type, $status, $phase, $address]) {
            $task = $this->task($type, $status);
            $response = $this->get(route('delivery.map', ['task' => $task->id]))->assertOk();
            $this->assertSame($phase, $response->viewData('stop')['phase']);
            $this->assertSame($address, $response->viewData('stop')['address']);
            $this->assertSame($task->id, $response->viewData('selected')->id);
        }
    }

    public function test_map_links_only_send_destination_and_opening_map_does_not_mutate_business_data(): void
    {
        $task = $this->task('delivery', 'in_custody');
        $before = $task->order->getAttributes();
        $audits = AuditLog::count();
        $response = $this->actingAs($this->shipper)->get(route('delivery.map', ['task' => $task->id]))->assertOk();
        $response->assertSee('Shipper và điểm nhận')->assertSee('Địa chỉ đơn')->assertSee('Chưa chọn vị trí shipper')
            ->assertSee('data-manual-select="shipper"', false)->assertSee('data-manual-select="destination"', false)
            ->assertSee('Đặt điểm tại tâm bản đồ')->assertSee('Vị trí thiết bị (tùy chọn)')->assertSee('data-shipper-map', false);
        $this->assertSame($this->depot->area->code, $response->viewData('demoArea'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $stop = $response->viewData('stop');
        foreach (['embed', 'directions'] as $field) {
            $url = $stop[$field];
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
            $this->assertContains(parse_url($url, PHP_URL_HOST), ['maps.google.com', 'www.google.com']);
            $this->assertStringContainsString('20 Tràng Tiền', rawurldecode($url));
            foreach ([$task->order->recipient_phone, $task->order->recipient_name, $task->order->tracking_code] as $private) {
                $this->assertStringNotContainsString($private, rawurldecode($url));
            }
        }
        $this->assertSame($before, $task->order->fresh()->getAttributes());
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, $task->order->collections()->count());
        $this->get(route('logistics.show', $task))->assertOk()->assertSee(route('delivery.map', ['task' => $task->id]), false);
    }

    public function test_map_excludes_other_shipper_foreign_warehouse_and_finished_tasks_even_by_id(): void
    {
        $own = $this->task();
        $otherShipper = $this->person('shipper');
        $otherShipper->warehouses()->attach($this->depot);
        $hidden = [$this->task(shipper: $otherShipper), $this->task(warehouse: $this->warehouse('HCM'))];
        foreach (['completed', 'cancelled', 'failed'] as $status) {
            $hidden[] = $this->task(status: $status);
        }
        $response = $this->actingAs($this->shipper)->get(route('delivery.map'))->assertOk()->assertSee($own->order->tracking_code);
        foreach ($hidden as $task) {
            $response->assertDontSee($task->order->tracking_code);
            $this->get(route('delivery.map', ['task' => $task->id]))->assertNotFound();
        }
        $this->assertSame(1, $response->viewData('counts')->sum());
    }

    public function test_filters_validation_pagination_and_missing_address_have_usable_states(): void
    {
        $this->actingAs($this->shipper);
        foreach ([['q' => ['bad']], ['type' => 'cod'], ['task' => -1], ['page' => ['bad']]] as $filters) {
            $this->getJson(route('delivery.map', $filters))->assertUnprocessable();
        }
        for ($index = 0; $index < 13; $index++) {
            $last = $this->task();
        }
        $delivery = $this->task('delivery', 'in_custody');
        $delivery->order->update(['recipient_address' => null]);
        $this->get(route('delivery.map'))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->count() === 12 && $tasks->total() === 14);
        $this->get(route('delivery.map', ['page' => 2]))->assertOk()->assertSee($last->order->tracking_code);
        $this->get(route('delivery.map', ['type' => 'delivery']))->assertOk()->assertSee('Chưa có địa chỉ điểm đến')->assertDontSee('<iframe', false);
        $this->get(route('delivery.map', ['q' => $last->order->tracking_code]))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->total() === 1);
        $this->get(route('delivery.map', ['q' => 'GV-UNKNOWN']))->assertOk()->assertSee('Chưa có điểm dừng phù hợp');
    }

    public function test_six_warehouse_accounts_cannot_read_recall_or_map_each_others_tasks(): void
    {
        $branches = [];
        foreach (['HN', 'HN', 'HCM', 'HCM', 'DN', 'DN'] as $area) {
            $warehouse = $this->warehouse($area);
            $operator = $this->person('operations');
            $shipper = $this->person('shipper');
            $operator->warehouses()->attach($warehouse);
            $shipper->warehouses()->attach($warehouse);
            $branches[] = [$warehouse, $operator, $shipper, $this->task(shipper: $shipper, warehouse: $warehouse)];
        }
        foreach ($branches as $index => [$warehouse, $operator, $shipper, $own]) {
            $listing = $this->actingAs($operator)->get(route('orders.index'))->assertOk()->assertSee($own->order->tracking_code);
            $this->get(route('logistics.show', $own))->assertOk();
            foreach ($branches as $otherIndex => [$otherWarehouse, $otherOperator, $otherShipper, $foreign]) {
                if ($otherIndex === $index) {
                    continue;
                }
                $listing->assertDontSee($foreign->order->tracking_code);
                $this->get(route('logistics.show', $foreign))->assertForbidden();
                $this->post(route('logistics.recall', $foreign), ['reason' => 'Thử thu hồi khác phạm vi'])->assertForbidden();
            }
            $map = $this->actingAs($shipper)->get(route('delivery.map'))->assertOk()->assertSee($own->order->tracking_code);
            $mine = $this->get(route('delivery.mine', ['type' => 'pickup']))->assertOk()->assertSee($own->order->tracking_code);
            foreach ($branches as $otherIndex => [$otherWarehouse, $otherOperator, $otherShipper, $foreign]) {
                if ($otherIndex !== $index) {
                    $map->assertDontSee($foreign->order->tracking_code);
                    $mine->assertDontSee($foreign->order->tracking_code);
                    $this->get(route('delivery.map', ['task' => $foreign->id]))->assertNotFound();
                    $this->get(route('logistics.show', $foreign))->assertForbidden();
                }
            }
        }
        $regional = $this->person('operations');
        $regional->warehouses()->attach([$branches[0][0]->id, $branches[1][0]->id]);
        $this->actingAs($regional);
        foreach ($branches as $index => [$warehouse, $operator, $shipper, $task]) {
            $this->get(route('logistics.show', $task))->assertStatus($index < 2 ? 200 : 403);
        }
        $this->assertSame(6, DeliveryTask::count());
        $this->assertSame(0, AuditLog::where('event', 'logistics.task_recalled')->count());
    }
}
