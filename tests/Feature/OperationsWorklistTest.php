<?php

namespace Tests\Feature;

use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\OrderService;
use App\Services\WarehouseService;
use App\Services\WarehouseTransferService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class OperationsWorklistTest extends DatabaseTestCase
{
    private User $operator;

    private User $receiver;

    private User $shipper;

    private User $owner;

    private Warehouse $source;

    private Warehouse $destination;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->source = $this->warehouse();
        $this->destination = $this->warehouse('HCM');
        $this->operator = $this->person('operations');
        $this->receiver = $this->person('operations');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        foreach ([$this->operator, $this->shipper] as $user) {
            $user->warehouses()->attach($this->source);
        }
        $this->receiver->warehouses()->attach($this->destination);
        $this->location = WarehouseLocation::create(['warehouse_id' => $this->source->id, 'code' => 'A', 'name' => 'Kệ tiếp nhận']);
        $admin = $this->person();
        $fees = app(FeeVersionService::class);
        $fees->publish($admin, $fees->save($admin, $this->feeData()), 'Áp dụng cước kiểm thử');
    }

    private function draft(bool $remote = false): Order
    {
        return app(OrderService::class)->saveDraft($this->owner, ['client_token' => (string) Str::uuid(),
            'pickup_address_id' => $this->owner->shop->addresses()->value('id'), 'warehouse_id' => $this->source->id,
            'area_id' => ($remote ? $this->destination : $this->source)->area_id, 'recipient_name' => 'Người nhận kiểm thử',
            'recipient_phone' => '0987656789', 'recipient_address' => '10 Đường nhận hàng', 'weight_grams' => 1000,
            'parcel_count' => 2, 'service' => 'standard', 'cod_amount' => 500000, 'fee_payer' => 'recipient',
            'items' => [['name' => 'Sách kiểm thử', 'quantity' => 2]]]);
    }

    private function approved(bool $remote = false): Order
    {
        $order = $this->draft($remote);
        $service = app(OrderService::class);
        $service->submit($this->owner, $order, $order->revision);
        $service->review($this->operator, $order->fresh(), $order->fresh()->revision, 'approved', 'Kiểm tra đủ thông tin');

        return $order->fresh();
    }

    private function assign(Order $order, string $type = 'pickup'): DeliveryTask
    {
        return app(DispatchService::class)->assign($this->operator, $order->fresh(), ['revision' => $order->fresh()->revision,
            'type' => $type, 'shipper_id' => $this->shipper->id, 'reason' => 'Phân công trong phạm vi kho']);
    }

    private function stocked(bool $remote = false): Order
    {
        $order = $this->approved($remote);
        $task = $this->assign($order);
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Lấy đủ hai kiện thực tế'], 'proof.png');
        $this->receiveTask($task);

        return $order->fresh();
    }

    private function receiveTask(DeliveryTask $task): void
    {
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->shipper, $task, 'inbound', 'Trả đủ kiện về kho');
        $service->confirm($this->operator, $doc, ['location_id' => $this->location->id, 'reason' => 'Kho kiểm đếm đủ kiện',
            'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all()], null);
    }

    public function test_unserved_destination_can_remain_draft_but_cannot_be_submitted_or_approved(): void
    {
        $draft = $this->draft(true);
        $this->destination->update(['is_active' => false]);
        $this->actingAs($this->owner)->get(route('orders.create'))->assertOk()
            ->assertViewHas('areas', fn ($areas) => $areas->has($this->source->area_id) && ! $areas->has($this->destination->area_id));
        $this->postJson(route('orders.submit', $draft), ['revision' => $draft->revision])->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, $draft->histories()->count());
        $this->destination->update(['is_active' => true]);
        $this->post(route('orders.submit', $draft), ['revision' => $draft->revision])->assertSessionHasNoErrors();
        $draft->refresh();
        $this->destination->update(['is_active' => false]);
        $this->actingAs($this->operator)->postJson(route('orders.review', $draft), ['revision' => $draft->revision, 'decision' => 'approved', 'reason' => 'Kiểm tra lại vùng phục vụ'])
            ->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->assertSame('submitted', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->approved_by);
    }

    public function test_work_queues_distinguish_pickup_transfer_and_local_delivery_and_keep_scope(): void
    {
        $pickup = $this->approved();
        $remote = $this->stocked(true);
        $local = $this->stocked();
        $this->actingAs($this->operator)->get(route('logistics.index', ['queue' => 'transfer']))->assertOk()
            ->assertViewHas('waiting', fn ($orders) => $orders->modelKeys() === [$remote->id])
            ->assertViewHas('queueCounts', fn ($counts) => $counts['pickup'] === 1 && $counts['transfer'] === 1 && $counts['delivery'] === 1)
            ->assertSee('Xuất chuyển kho');
        $this->get(route('logistics.index', ['queue' => 'delivery']))->assertOk()
            ->assertViewHas('waiting', fn ($orders) => $orders->modelKeys() === [$local->id]);
        $this->get(route('logistics.index', ['queue' => 'pickup']))->assertOk()
            ->assertViewHas('waiting', fn ($orders) => $orders->modelKeys() === [$pickup->id]);
        $this->actingAs($this->receiver)->get(route('logistics.index', ['warehouse_id' => $this->source->id]))->assertOk()
            ->assertViewHas('waiting', fn ($orders) => $orders->isEmpty())->assertViewHas('queueCounts', fn ($counts) => array_sum($counts) === 0)
            ->assertDontSee($remote->tracking_code)->assertDontSee($local->tracking_code);
        $this->getJson(route('logistics.index', ['queue' => 'forged']))->assertUnprocessable();
    }

    public function test_hold_hides_assignment_and_transfer_forms_and_direct_requests_stay_blocked(): void
    {
        $order = $this->stocked(true);
        Discrepancy::create(['warehouse_id' => $this->source->id, 'order_id' => $order->id, 'warehouse_item_id' => $order->parcels->first()->id,
            'created_by' => $this->operator->id, 'type' => 'damaged', 'severity' => 'high', 'reason' => 'Hỏng kiện cần xác minh']);
        $this->actingAs($this->operator)->get(route('logistics.order', $order))->assertOk()->assertSee('Đang giữ kiện do sai lệch')
            ->assertDontSee('action="'.route('logistics.assign', $order).'"', false)->assertDontSee('action="'.route('transfers.store', $order).'"', false);
        $this->get(route('logistics.index', ['queue' => 'held']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.index', ['queue' => 'transfer']))->assertOk()->assertDontSee($order->tracking_code);
        $this->postJson(route('logistics.assign', $order), ['revision' => $order->revision, 'type' => 'delivery', 'shipper_id' => $this->shipper->id, 'reason' => 'Thử vượt giữ kiện'])->assertUnprocessable();
        $this->assertSame('at_warehouse', $order->fresh()->status);
    }

    public function test_pending_handover_has_one_next_action_and_duplicate_prepare_cannot_write(): void
    {
        $task = $this->assign($this->stocked(), 'delivery');
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->operator, $task, 'outbound', 'Xuất đủ kiện theo nhiệm vụ');
        $this->actingAs($this->operator)->get(route('logistics.show', $task))->assertOk()->assertSee('Mở phiếu bàn giao')
            ->assertDontSee('action="'.route('logistics.prepare', $task).'"', false);
        $this->postJson(route('logistics.prepare', $task), ['direction' => 'outbound', 'reason' => 'Thử lập phiếu trùng'])->assertUnprocessable();
        $this->assertSame(1, $task->handovers()->count());
        $this->actingAs($this->shipper)->get(route('logistics.show', $task))->assertOk()->assertSee('Chờ shipper kiểm đếm')
            ->assertDontSee('action="'.route('delivery.record', $task).'"', false);
        $service->confirm($this->shipper, $doc, ['reason' => 'Nhận đủ hai kiện nguyên vẹn', 'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null);
        $returnDoc = $service->prepare($this->shipper, $task, 'inbound', 'Chủ động trả lại kiện chưa giao');
        $this->get(route('logistics.show', $task))->assertOk()->assertSee(route('logistics.handover', $returnDoc))
            ->assertDontSee('action="'.route('delivery.record', $task).'"', false)->assertDontSee('action="'.route('logistics.prepare', $task).'"', false);
    }

    public function test_failed_delivery_requires_decision_before_assignment_and_does_not_offer_retry_twice(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $stock = app(WarehouseService::class);
        $doc = $stock->prepare($this->operator, $task, 'outbound', 'Xuất giao đủ hai kiện');
        $stock->confirm($this->shipper, $doc, ['reason' => 'Shipper nhận đủ hai kiện', 'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null);
        app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => 'absent', 'reason' => 'Người nhận vắng nhà khi giao'], null);
        $this->receiveTask($task);
        $order->refresh();
        $this->actingAs($this->operator)->get(route('logistics.index', ['queue' => 'decision']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.order', $order))->assertOk()->assertSee('Cần quyết định giao lại')
            ->assertDontSee('action="'.route('logistics.assign', $order).'"', false);
        app(DeliveryDecisionService::class)->decide($this->operator, $order, ['revision' => $order->revision, 'decision' => 'retry', 'reason' => 'Xác minh người nhận đã về nhà', 'shop_instruction' => 'Người gửi đồng ý giao lại']);
        $this->get(route('logistics.order', $order))->assertOk()->assertSee('action="'.route('logistics.assign', $order).'"', false)
            ->assertDontSee('<option value="retry"', false);
        $this->get(route('logistics.index', ['queue' => 'delivery']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.index', ['queue' => 'decision']))->assertOk()->assertDontSee($order->tracking_code);
        $this->assertSame(0, $order->collections()->count());
    }

    public function test_receiving_queue_only_exposes_transfer_to_destination_and_filters_counts_together(): void
    {
        $order = $this->stocked(true);
        $transfer = app(WarehouseTransferService::class)->dispatch($this->operator, $order, ['revision' => $order->revision,
            'destination_warehouse_id' => $this->destination->id, 'carrier' => 'Nhà xe kiểm thử', 'transport_reference' => 'TEST-01', 'reason' => 'Chuyển đủ hai kiện đến kho giao']);
        $this->actingAs($this->receiver)->get(route('logistics.index', ['queue' => 'receipt']))->assertOk()->assertSee($order->tracking_code)
            ->assertSee(route('transfers.show', $transfer))->assertDontSee('href="'.route('logistics.order', $order).'"', false)
            ->assertViewHas('queueCounts', fn ($counts) => $counts['receipt'] === 1 && $counts['all'] === 1);
        $this->get(route('logistics.order', $order))->assertForbidden();
        $this->get(route('logistics.index', ['queue' => 'receipt', 'q' => 'MISSING']))->assertOk()->assertDontSee($order->tracking_code)
            ->assertViewHas('queueCounts', fn ($counts) => $counts['receipt'] === 0 && $counts['all'] === 0);
        $this->actingAs($this->operator)->get(route('logistics.index', ['queue' => 'receipt']))->assertOk()->assertDontSee($order->tracking_code);
    }

    public function test_coverage_removed_after_assignment_blocks_successful_pickup_without_creating_custody(): void
    {
        $order = $this->approved(true);
        $task = $this->assign($order);
        $this->destination->update(['is_active' => false]);
        $this->actingAs($this->shipper)->postJson(route('logistics.pickup', $task), ['successful' => true, 'condition' => 'good', 'reason' => 'Thử nhận hàng tuyến đã đóng',
            'evidence' => UploadedFile::fake()->image('proof.jpg')])->assertUnprocessable()->assertJsonValidationErrors('area_id');
        $this->assertSame(0, $order->parcels()->count());
        $this->assertSame('assigned', $task->fresh()->status);
        $this->assertNull($task->fresh()->pickupAttempt);
        app(DispatchService::class)->recall($this->operator, $task, 'Thu hồi để sửa tuyến chưa phục vụ');
        $this->actingAs($this->operator)->get(route('logistics.index', ['queue' => 'held']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.order', $order))->assertOk()->assertSee('Khu vực người nhận chưa có kho giao')
            ->assertDontSee('action="'.route('logistics.assign', $order).'"', false);
    }

    public function test_handover_rejects_parcel_in_another_warehouse_and_retains_pending_document(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $doc = app(WarehouseService::class)->prepare($this->operator, $task, 'outbound', 'Lập phiếu xuất giao tại kho');
        $item = $order->parcels->first();
        $item->update(['warehouse_id' => $this->destination->id]);
        $this->actingAs($this->shipper)->postJson(route('logistics.confirm', $doc), ['reason' => 'Thử nhận kiện kho khác',
            'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()])->assertUnprocessable();
        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertSame('assigned', $task->fresh()->status);
        $this->assertSame('warehouse', $item->fresh()->holder_type);
    }

    public function test_worklist_query_count_is_bounded_as_the_page_fills(): void
    {
        $this->approved();
        $this->actingAs($this->operator);
        DB::enableQueryLog();
        $this->get(route('logistics.index', ['queue' => 'pickup']))->assertOk();
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 11; $i++) {
            $this->approved();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('logistics.index', ['queue' => 'pickup']))->assertOk()->assertViewHas('waiting', fn ($orders) => $orders->count() === 12);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 5, $many, 'Danh sách không được query riêng cho từng vận đơn.');
    }

    public function test_return_transfer_waits_for_an_active_original_warehouse(): void
    {
        $order = $this->stocked(true);
        $transfers = app(WarehouseTransferService::class);
        $transfer = $transfers->dispatch($this->operator, $order, ['revision' => $order->revision,
            'destination_warehouse_id' => $this->destination->id, 'carrier' => 'Xe kiểm thử', 'transport_reference' => 'TEST-RETURN', 'reason' => 'Chuyển đến kho người nhận']);
        $location = WarehouseLocation::create(['warehouse_id' => $this->destination->id, 'code' => 'A', 'name' => 'Kệ nhận hàng']);
        $transfers->receive($this->receiver, $transfer, ['revision' => $order->fresh()->revision, 'location_id' => $location->id,
            'reason' => 'Nhận đủ hai kiện nguyên vẹn', 'lines' => $transfer->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null);
        app(DeliveryDecisionService::class)->decide($this->receiver, $order->fresh(), ['revision' => $order->fresh()->revision,
            'decision' => 'return', 'reason' => 'Shop yêu cầu hoàn hai kiện', 'shop_instruction' => 'Người gửi yêu cầu hoàn về địa chỉ gửi']);
        $this->source->update(['is_active' => false]);
        $this->actingAs($this->receiver)->get(route('logistics.order', $order))->assertOk()->assertSee('Kho gửi ban đầu đang ngừng hoạt động')
            ->assertDontSee('action="'.route('transfers.store', $order).'"', false);
        $this->get(route('logistics.index', ['queue' => 'held']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.index', ['queue' => 'transfer']))->assertOk()->assertDontSee($order->tracking_code);
        $this->postJson(route('transfers.store', $order), ['revision' => $order->fresh()->revision,
            'destination_warehouse_id' => $this->source->id, 'carrier' => 'Xe kiểm thử', 'transport_reference' => 'TEST-RETURN', 'reason' => 'Thử chuyển về kho đã đóng'])->assertUnprocessable();
        $this->assertSame(1, $order->transfers()->count());
        $this->assertSame('at_warehouse', $order->fresh()->status);
        $this->source->update(['is_active' => true]);
        $this->get(route('logistics.index', ['queue' => 'transfer']))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('logistics.order', $order))->assertOk()->assertSee('action="'.route('transfers.store', $order).'"', false);
    }
}
