<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\HandoverDocument;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseItem;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\OrderService;
use App\Services\StocktakeService;
use App\Services\WarehouseService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class LogisticsWorkflowTest extends DatabaseTestCase
{
    private User $admin;

    private User $dispatcher;

    private User $clerk;

    private User $shipper;

    private User $owner;

    private Warehouse $depot;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->person();
        $this->dispatcher = $this->person('dispatcher');
        $this->clerk = $this->person('warehouse');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        foreach ([$this->dispatcher, $this->clerk, $this->shipper] as $user) {
            $user->warehouses()->attach($this->depot);
        }
        $this->location = WarehouseLocation::create(['warehouse_id' => $this->depot->id, 'code' => 'A-01', 'name' => 'Kệ A-01']);
        $fees = app(FeeVersionService::class);
        $fees->publish($this->admin, $fees->save($this->admin, $this->feeData()), 'Biểu phí kiểm thử');
    }

    private function approved(): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->saveDraft($this->owner, ['client_token' => (string) Str::uuid(), 'pickup_address_id' => $this->owner->shop->addresses()->value('id'),
            'area_id' => Area::where('code', 'HCM')->value('id'), 'recipient_name' => 'Người nhận', 'recipient_phone' => '0987656789', 'recipient_address' => '10 Đường kiểm thử',
            'weight_grams' => 1000, 'parcel_count' => 2, 'service' => 'standard', 'cod_amount' => 500000, 'fee_payer' => 'recipient', 'items' => [['name' => 'Sách', 'quantity' => 2]]]);
        $orders->submit($this->owner, $order, $order->revision);
        $orders->review($this->admin, $order->fresh(), $order->fresh()->revision, 'approved', 'Thông tin hợp lệ');
        $order->refresh()->update(['warehouse_id' => $this->depot->id]);

        return $order->fresh();
    }

    private function assign(Order $order, string $type = 'pickup', ?User $shipper = null): DeliveryTask
    {
        return app(DispatchService::class)->assign($this->dispatcher, $order, ['revision' => $order->fresh()->revision, 'type' => $type, 'shipper_id' => ($shipper ?? $this->shipper)->id, 'reason' => 'Phân công tuyến kiểm thử']);
    }

    private function picked(): DeliveryTask
    {
        $task = $this->assign($this->approved());
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Đã lấy đủ hai kiện'], 'proof.png');

        return $task->fresh();
    }

    private function receipt(DeliveryTask $task): HandoverDocument
    {
        return app(WarehouseService::class)->prepare($this->shipper, $task, 'inbound', 'Bàn giao đủ về kho');
    }

    private function confirmation(HandoverDocument $doc, array $overrides = []): array
    {
        $lines = [];
        foreach ($doc->lines as $line) {
            $lines[$line->id] = ['received' => true, 'condition' => 'good', 'weight_grams' => 500];
        }

        return array_replace_recursive(['lines' => $lines, 'location_id' => $this->location->id, 'reason' => 'Đã kiểm đếm thực tế'], $overrides);
    }

    private function stocked(): Order
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        app(WarehouseService::class)->confirm($this->clerk, $doc, $this->confirmation($doc), 'proof.png');

        return $task->order->fresh();
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Thao tác phải bị từ chối.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_assignment_does_not_change_holder_and_duplicate_task_is_blocked(): void
    {
        $order = $this->approved();
        $task = $this->assign($order);
        $this->assertSame('uncollected', $order->fresh()->custody_status);
        $this->assertSame(0, $order->parcels()->count());
        $this->invalid(fn () => $this->assign($order));
        $this->assertSame(1, $order->tasks()->count());
        $this->assertSame(1, $task->assignments()->count());
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'event' => 'task_assigned']);
        $this->expectException(UniqueConstraintViolationException::class);
        DeliveryTask::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'shipper_id' => $this->shipper->id, 'created_by' => $this->admin->id, 'type' => 'pickup', 'reason' => 'Bypass service']);
    }

    public function test_scope_permissions_filters_and_evidence_are_enforced(): void
    {
        $task = $this->picked();
        $outsider = $this->person('shipper');
        $outsider->warehouses()->attach($this->depot);
        $this->get('/dieu-phoi')->assertRedirect('/dang-nhap');
        $this->actingAs($this->owner)->get('/dieu-phoi')->assertForbidden();
        $this->actingAs($outsider)->get('/nhiem-vu/'.$task->id)->assertForbidden();
        $this->get('/bang-chung/pickup/'.$task->pickupAttempt->id)->assertForbidden();
        $this->get('/dieu-phoi')->assertOk()->assertDontSee($task->order->tracking_code);
        $this->actingAs($this->shipper)->get('/dieu-phoi')->assertOk()->assertSee($task->order->tracking_code);
        $this->get('/ton-kho')->assertForbidden();
        $this->actingAs($this->person('dispatcher'))->get('/van-hanh/don/'.$task->order_id)->assertForbidden();
        $this->actingAs($this->clerk)->get('/dieu-phoi?status[]=assigned')->assertSessionHasErrors('status');
        $this->actingAs($outsider)->post('/nhiem-vu/'.$task->id.'/lay-hang', ['successful' => 1, 'condition' => 'good', 'reason' => 'Không thuộc nhiệm vụ', 'evidence' => $this->photo()])->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aU1kAAAAASUVORK5CYII='));
    }

    public function test_pickup_success_is_atomic_and_retry_does_not_create_more_parcels(): void
    {
        $task = $this->assign($this->approved());
        $data = ['successful' => 1, 'condition' => 'good', 'reason' => 'Nhận đủ hàng từ shop', 'evidence' => $this->photo()];
        $this->actingAs($this->shipper)->post('/nhiem-vu/'.$task->id.'/lay-hang', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('picked_up', $task->order->fresh()->status);
        $this->assertSame(2, WarehouseItem::where('order_id', $task->order_id)->where('holder_user_id', $this->shipper->id)->count());
        $this->post('/nhiem-vu/'.$task->id.'/lay-hang', array_replace($data, ['evidence' => $this->photo()]))->assertSessionHasErrors();
        $this->assertSame(2, $task->order->parcels()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/bang-chung/pickup/'.$task->pickupAttempt->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_failed_pickup_allows_a_new_task_and_records_one_attempt(): void
    {
        $task = $this->assign($this->approved());
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => false, 'condition' => 'good', 'reason' => 'Shop đóng cửa khi đến'], 'proof.png');
        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('uncollected', $task->order->fresh()->custody_status);
        $this->assertSame(0, $task->order->parcels()->count());
        $this->assertNotSame($task->id, $this->assign($task->order)->id);
    }

    public function test_pending_change_outside_shipper_and_stale_revision_block_assignment(): void
    {
        $order = $this->approved();
        $outside = $this->person('shipper');
        $this->invalid(fn () => $this->assign($order, 'pickup', $outside));
        $this->actingAs($this->dispatcher)->post('/van-hanh/don/'.$order->id.'/phan-cong', ['revision' => $order->revision - 1, 'type' => 'pickup', 'shipper_id' => $this->shipper->id, 'reason' => 'Dữ liệu phiên cũ'])->assertSessionHasErrors('revision');
        app(OrderService::class)->requestChange($this->owner, $order, $order->revision, 'cancel', [], 'Khách muốn hủy trước khi lấy');
        $this->invalid(fn () => $this->assign($order));
    }

    public function test_order_edit_requires_recall_before_pickup(): void
    {
        $task = $this->assign($this->approved());
        $order = $task->order;
        $this->invalid(fn () => app(OrderService::class)->requestChange($this->owner, $order, $order->revision, 'cancel', [], 'Khách yêu cầu hủy đơn'));
        app(DispatchService::class)->recall($this->dispatcher, $task, 'Thu hồi trước khi nhận kiện');
        app(OrderService::class)->requestChange($this->owner, $order->fresh(), $order->fresh()->revision, 'cancel', [], 'Khách yêu cầu hủy đơn');
        $this->assertDatabaseHas('order_change_requests', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_receipt_moves_each_parcel_and_cannot_be_confirmed_twice(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $this->assertSame('shipper', $task->order->fresh()->custody_status);
        $this->actingAs($this->clerk)->get('/ban-giao/'.$doc->id)->assertOk()->assertSee('Cân thực tế');
        $this->post('/ban-giao/'.$doc->id.'/xac-nhan', $this->confirmation($doc))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('warehouse', $task->order->fresh()->custody_status);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('at_warehouse', $task->order->fresh()->status);
        $this->assertSame(2, $task->order->parcels()->where('warehouse_location_id', $this->location->id)->count());
        $this->invalid(fn () => app(WarehouseService::class)->confirm($this->clerk, $doc, $this->confirmation($doc), null));
        $this->assertSame(1, $task->handovers()->count());
    }

    public function test_partial_receipt_keeps_original_shipper_and_blocks_reassignment(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $line = $doc->lines->last();
        $data = $this->confirmation($doc, ['lines' => [$line->id => ['received' => false]]]);
        $this->invalid(fn () => app(WarehouseService::class)->confirm($this->clerk, $doc, $data, null));
        $this->assertSame(0, $task->order->parcels()->where('holder_type', 'warehouse')->count());
        app(WarehouseService::class)->confirm($this->clerk, $doc, $data, 'proof.png');
        $this->assertSame('mixed', $task->order->fresh()->custody_status);
        $this->assertSame('in_custody', $task->fresh()->status);
        $this->assertSame(1, $task->order->parcels()->where('holder_user_id', $this->shipper->id)->count());
        $this->invalid(fn () => $this->assign($task->order, 'delivery'));
        $remaining = $this->receipt($task);
        $this->assertSame(1, $remaining->lines()->count());
        app(WarehouseService::class)->confirm($this->clerk, $remaining, $this->confirmation($remaining), 'proof.png');
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame(1, $task->order->discrepancies()->where('status', 'open')->count());
    }

    public function test_receipt_rejects_foreign_location_or_forged_lines(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $location = WarehouseLocation::create(['warehouse_id' => $this->warehouse('HCM')->id, 'code' => 'OTHER', 'name' => 'Ngoài phạm vi']);
        $this->invalid(fn () => app(WarehouseService::class)->confirm($this->clerk, $doc, $this->confirmation($doc, ['location_id' => $location->id]), null));
        $data = $this->confirmation($doc);
        unset($data['lines'][$doc->lines->first()->id]);
        $this->invalid(fn () => app(WarehouseService::class)->confirm($this->clerk, $doc, $data, null));
        $this->assertSame('pending', $doc->fresh()->status);
    }

    public function test_weight_discrepancy_holds_order_preserves_fees_and_needs_another_approver(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $fee = $task->order->shipping_fee;
        $data = $this->confirmation($doc, ['lines' => [$doc->lines->first()->id => ['weight_grams' => 550]]]);
        app(WarehouseService::class)->confirm($this->clerk, $doc, $data, 'proof.png');
        $difference = $task->order->discrepancies()->firstOrFail();
        $this->assertSame('weight', $difference->type);
        $this->assertSame($fee, $task->order->fresh()->shipping_fee);
        $this->assertSame(1000, $task->order->fresh()->weight_grams);
        $this->invalid(fn () => $this->assign($task->order, 'delivery'));
        $this->actingAs($this->clerk)->post('/sai-lech/'.$difference->id.'/xu-ly', ['reason' => 'Tự xác nhận sai lệch'])->assertForbidden();
        app(WarehouseService::class)->resolve($this->dispatcher, $difference, 'Cân đối chứng, giữ cước đã thỏa thuận với shop');
        $this->assertSame('resolved', $difference->fresh()->status);
        $this->assertSame('assigned', $this->assign($task->order, 'delivery')->status);
    }

    public function test_outbound_requires_two_sides_and_rechecks_hold_on_confirmation(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $doc = app(WarehouseService::class)->prepare($this->clerk, $task, 'outbound', 'Xuất giao theo tuyến');
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->assertSame(2, $order->parcels()->where('holder_type', 'warehouse')->count());
        $difference = Discrepancy::create(['warehouse_id' => $this->depot->id, 'order_id' => $order->id, 'warehouse_item_id' => $order->parcels()->first()->id, 'created_by' => $this->clerk->id, 'type' => 'damaged', 'severity' => 'high', 'reason' => 'Phát hiện hư hỏng trước lúc giao']);
        $this->invalid(fn () => app(WarehouseService::class)->confirm($this->shipper, $doc, $this->confirmation($doc), null));
        app(WarehouseService::class)->resolve($this->dispatcher, $difference, 'Đã kiểm tra và đóng gói lại');
        $this->actingAs($this->clerk)->post('/ban-giao/'.$doc->id.'/xac-nhan', $this->confirmation($doc))->assertForbidden();
        app(WarehouseService::class)->confirm($this->shipper, $doc, $this->confirmation($doc), null);
        $this->assertSame('shipper', $order->fresh()->custody_status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->delivery_attempt_count);
    }

    public function test_outbound_mismatch_keeps_missing_parcel_in_warehouse_and_recalls_received(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $doc = app(WarehouseService::class)->prepare($this->clerk, $task, 'outbound', 'Xuất giao theo tuyến');
        app(WarehouseService::class)->confirm($this->shipper, $doc, $this->confirmation($doc, ['lines' => [$doc->lines->last()->id => ['received' => false]]]), 'proof.png');
        $this->assertSame('recall_pending', $task->fresh()->status);
        $this->assertSame('mixed', $order->fresh()->custody_status);
        $this->assertSame(1, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->assertDatabaseHas('discrepancies', ['order_id' => $order->id, 'type' => 'missing']);
    }

    public function test_recall_after_custody_requires_full_return_before_reassigning(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->clerk, $task, 'outbound', 'Xuất giao theo tuyến');
        $service->confirm($this->shipper, $doc, $this->confirmation($doc), null);
        app(DispatchService::class)->recall($this->dispatcher, $task, 'Shipper kết thúc ca, trả hàng về kho');
        $this->assertSame('recall_pending', $task->fresh()->status);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        $return = $this->receipt($task);
        $service->confirm($this->clerk, $return, $this->confirmation($return), 'proof.png');
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('assigned', $this->assign($order, 'delivery')->status);
    }

    public function test_recall_before_custody_cancels_pending_outbound_and_allows_new_shipper(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->clerk, $task, 'outbound', 'Xuất giao theo tuyến');
        app(DispatchService::class)->recall($this->dispatcher, $task, 'Đổi shipper trước khi nhận kiện');
        $this->assertSame('cancelled', $doc->fresh()->status);
        $this->invalid(fn () => $service->confirm($this->shipper, $doc, $this->confirmation($doc), null));
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->assertNotSame($task->id, $this->assign($order, 'delivery')->id);
    }

    public function test_return_requires_reasoned_decision_and_three_delivery_attempts_block_more_delivery(): void
    {
        $order = $this->stocked();
        $this->invalid(fn () => $this->assign($order, 'return'));
        $order->update(['delivery_attempt_count' => 3]);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        app(DispatchService::class)->authorizeReturn($this->dispatcher, $order, 'Shop đồng ý hoàn sau ba lượt thất bại');
        $this->assertSame('assigned', $this->assign($order, 'return')->status);
    }

    public function test_stocktake_retains_snapshot_and_does_not_erase_missing_inventory(): void
    {
        $order = $this->stocked();
        $service = app(StocktakeService::class);
        $stocktake = $service->start($this->clerk, ['warehouse_id' => $this->depot->id, 'location_id' => $this->location->id, 'reason' => 'Kiểm kê kết thúc ca']);
        $lines = $stocktake->lines->mapWithKeys(fn ($line) => [$line->id => ['actual_present' => false, 'note' => 'Không tìm thấy tại kệ']])->all();
        $service->submit($this->clerk, $stocktake, ['lines' => $lines, 'unexpected_note' => 'Một kiện không có nhãn']);
        $this->actingAs($this->clerk)->get('/kiem-ke/'.$stocktake->id)->assertOk();
        $this->actingAs($this->dispatcher)->post('/kiem-ke/'.$stocktake->id.'/duyet', ['reason' => 'Xác nhận kiểm đếm và yêu cầu xác minh'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $stocktake->fresh()->status);
        $this->assertSame(2, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->assertSame(2, $stocktake->discrepancies()->where('type', 'stock_missing')->count());
        $this->assertSame(1, $stocktake->discrepancies()->where('type', 'stock_extra')->count());
        $this->assertSame($this->dispatcher->id, $stocktake->fresh()->approved_by);
        $this->invalid(fn () => $service->approve($this->dispatcher, $stocktake, 'Duyệt lặp biên bản'));
        $this->invalid(fn () => $this->assign($order, 'delivery'));
    }

    public function test_stocktake_rejects_self_approval_forged_lines_and_reports_movements(): void
    {
        $order = $this->stocked();
        $service = app(StocktakeService::class);
        $stocktake = $service->start($this->admin, ['warehouse_id' => $this->depot->id, 'reason' => 'Kiểm kê toàn kho']);
        $this->invalid(fn () => $service->submit($this->admin, $stocktake, ['lines' => []]));
        $lines = $stocktake->lines->mapWithKeys(fn ($line) => [$line->id => ['actual_present' => true]])->all();
        $service->submit($this->admin, $stocktake, ['lines' => $lines]);
        $this->invalid(fn () => $service->approve($this->admin, $stocktake, 'Tự duyệt biên bản'));
        $task = $this->assign($order, 'delivery');
        $doc = app(WarehouseService::class)->prepare($this->clerk, $task, 'outbound', 'Xuất hàng sau snapshot');
        app(WarehouseService::class)->confirm($this->shipper, $doc, $this->confirmation($doc), null);
        $service->approve($this->dispatcher, $stocktake, 'Ghi nhận hàng đã xuất sau snapshot');
        $this->assertSame(2, $stocktake->discrepancies()->where('type', 'stock_movement')->where('blocks_dispatch', false)->count());
        $this->assertSame('shipper', $order->fresh()->custody_status);
        $this->assertSame(2, $stocktake->lines()->where('movement_detected', true)->count());
    }

    public function test_audit_failure_rolls_back_receipt_holder_and_history(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $count = $task->order->histories()->count();
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(WarehouseService::class)->confirm($this->clerk, $doc, $this->confirmation($doc), null);
            $this->fail('Phải rollback khi audit thất bại.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertSame('shipper', $task->order->fresh()->custody_status);
        $this->assertSame($count, $task->order->histories()->count());
        $this->assertSame(0, $doc->lines()->whereNotNull('received')->count());
    }

    public function test_measured_weight_requires_separate_review_and_retains_the_original_quote(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $data = $this->confirmation($doc, ['lines' => [$doc->lines->first()->id => ['weight_grams' => 1100]]]);
        $original = $task->order->shipping_fee;
        $service = app(WarehouseService::class);
        $service->confirm($this->clerk, $doc, $data, 'proof.png');
        $difference = $task->order->discrepancies()->where('type', 'weight')->firstOrFail();
        $service->requestWeightReview($this->clerk, $difference, 'Cân đối chứng cùng shop, đề nghị tính lại cước');
        $change = $task->order->pendingChange()->firstOrFail();
        $this->assertSame($original, $change->before_values['shipping_fee']);
        $this->assertSame($original, $task->order->fresh()->shipping_fee);
        $this->invalid(fn () => $service->resolve($this->dispatcher, $difference, 'Bỏ giữ khi chưa duyệt'));
        $this->actingAs($this->dispatcher)->get('/don-hang/'.$task->order_id)->assertOk()->assertSee('cân thực tế');
        app(OrderService::class)->reviewChange($this->dispatcher, $change, 'approved', 'Shop đồng ý áp dụng cước theo 1600 gram');
        $this->assertSame(1600, $task->order->fresh()->weight_grams);
        $this->assertGreaterThan((int) $original, (int) $task->order->fresh()->shipping_fee);
        $this->assertSame('at_warehouse', $task->order->fresh()->status);
        $this->assertSame('open', $difference->fresh()->status);
        $service->resolve($this->dispatcher, $difference, 'Đã duyệt cân và cước, cho phép xuất');
        $this->assertSame('assigned', $this->assign($task->order, 'delivery')->status);
    }

    public function test_damage_needs_evidence_and_even_admin_cannot_release_own_hold(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $data = $this->confirmation($doc, ['lines' => [$doc->lines->first()->id => ['condition' => 'damaged']]]);
        $service = app(WarehouseService::class);
        $this->invalid(fn () => $service->confirm($this->admin, $doc, $data, null));
        $service->confirm($this->admin, $doc, $data, 'proof.png');
        $difference = $task->order->discrepancies()->where('type', 'damaged')->firstOrFail();
        $this->invalid(fn () => $service->resolve($this->admin, $difference, 'Tự bỏ giữ kiện của mình'));
        $this->assertSame('open', $difference->fresh()->status);
    }

    public function test_stale_weight_proposal_can_be_rejected_without_applying_old_values(): void
    {
        $task = $this->picked();
        $doc = $this->receipt($task);
        $service = app(WarehouseService::class);
        $service->confirm($this->clerk, $doc, $this->confirmation($doc, ['lines' => [$doc->lines->first()->id => ['weight_grams' => 600]]]), 'proof.png');
        $difference = $task->order->discrepancies()->where('type', 'weight')->firstOrFail();
        $service->requestWeightReview($this->clerk, $difference, 'Đề nghị xem xét cân thực tế');
        $change = $task->order->pendingChange()->firstOrFail();
        $stocktakes = app(StocktakeService::class);
        $stocktake = $stocktakes->start($this->clerk, ['warehouse_id' => $this->depot->id, 'reason' => 'Kiểm kê có sai lệch mới']);
        $lines = $stocktake->lines->mapWithKeys(fn ($line) => [$line->id => ['actual_present' => false, 'note' => 'Chưa tìm thấy khi kiểm kê']])->all();
        $stocktakes->submit($this->clerk, $stocktake, ['lines' => $lines]);
        $stocktakes->approve($this->dispatcher, $stocktake, 'Lập hồ sơ xác minh thêm');
        $this->invalid(fn () => app(OrderService::class)->reviewChange($this->dispatcher, $change, 'approved', 'Không được dùng revision cũ'));
        app(OrderService::class)->reviewChange($this->dispatcher, $change, 'rejected', 'Từ chối đề nghị cũ để lập lại sau xác minh');
        $this->assertSame('rejected', $change->fresh()->status);
        $this->assertSame(1000, $task->order->fresh()->weight_grams);
        $this->assertNull($task->order->pendingChange()->first());
    }

    public function test_stocktake_can_save_counts_in_batches_but_cannot_submit_an_incomplete_snapshot(): void
    {
        $this->stocked();
        $service = app(StocktakeService::class);
        $stocktake = $service->start($this->clerk, ['warehouse_id' => $this->depot->id, 'reason' => 'Kiểm kê từng trang']);
        $first = $stocktake->lines->first();
        $last = $stocktake->lines->last();
        $service->submit($this->clerk, $stocktake, ['lines' => [$first->id => ['actual_present' => true]], 'finalize' => false]);
        $this->assertSame('draft', $stocktake->fresh()->status);
        $this->assertSame(1, $stocktake->lines()->whereNull('actual_present')->count());
        $this->invalid(fn () => $service->submit($this->clerk, $stocktake, ['finalize' => true]));
        $service->submit($this->clerk, $stocktake, ['lines' => [$last->id => ['actual_present' => true]], 'finalize' => true]);
        $this->assertSame('submitted', $stocktake->fresh()->status);
    }

    public function test_all_logistics_pages_render_for_scoped_staff_and_hide_foreign_warehouse(): void
    {
        $order = $this->stocked();
        foreach (['/dieu-phoi', '/van-hanh/don/'.$order->id, '/ton-kho', '/kiem-ke'] as $url) {
            $this->actingAs($this->clerk)->get($url)->assertOk();
            $this->actingAs($this->dispatcher)->get($url)->assertOk();
        }
        $outsider = $this->person('warehouse');
        $this->actingAs($outsider)->get('/ton-kho')->assertOk()->assertDontSee($order->tracking_code);
        $this->get('/van-hanh/don/'.$order->id)->assertForbidden();
        $this->assertTrue(AuditLog::where('event', 'logistics.handover_confirmed')->exists());
    }
}
