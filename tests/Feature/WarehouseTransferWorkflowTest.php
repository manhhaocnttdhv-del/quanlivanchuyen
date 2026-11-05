<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DeliveryTask;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Services\AuditService;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\PublicTrackingService;
use App\Services\WarehouseService;
use App\Services\WarehouseTransferService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class WarehouseTransferWorkflowTest extends DatabaseTestCase
{
    private User $sender;

    private User $receiver;

    private User $sourceShipper;

    private User $destinationShipper;

    private User $owner;

    private User $admin;

    private Warehouse $source;

    private Warehouse $destination;

    private WarehouseLocation $sourceLocation;

    private WarehouseLocation $destinationLocation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->source = $this->warehouse();
        $this->destination = $this->warehouse('HCM');
        $this->sender = $this->person('operations');
        $this->receiver = $this->person('operations');
        $this->sourceShipper = $this->person('shipper');
        $this->destinationShipper = $this->person('shipper');
        foreach ([$this->sender, $this->sourceShipper] as $user) {
            $user->warehouses()->attach($this->source);
        }
        foreach ([$this->receiver, $this->destinationShipper] as $user) {
            $user->warehouses()->attach($this->destination);
        }
        $this->sourceLocation = WarehouseLocation::create(['warehouse_id' => $this->source->id, 'code' => 'A', 'name' => 'Kệ kho gửi']);
        $this->destinationLocation = WarehouseLocation::create(['warehouse_id' => $this->destination->id, 'code' => 'A', 'name' => 'Kệ kho nhận']);
        $this->owner = $this->shopOwner();
        $this->admin = $this->person();
        $fees = app(FeeVersionService::class);
        $fees->publish($this->admin, $fees->save($this->admin, $this->feeData()), 'Áp dụng cước liên tỉnh');
    }

    private function stocked(): Order
    {
        $service = app(OrderService::class);
        $order = $service->saveDraft($this->owner, ['client_token' => (string) Str::uuid(),
            'pickup_address_id' => $this->owner->shop->addresses()->value('id'), 'warehouse_id' => $this->source->id,
            'area_id' => $this->destination->area_id, 'recipient_name' => 'Khách liên tỉnh', 'recipient_phone' => '0987656789',
            'recipient_address' => '10 Đường nhận hàng, TP.HCM', 'weight_grams' => 1000, 'parcel_count' => 2,
            'service' => 'standard', 'cod_amount' => 500000, 'fee_payer' => 'recipient', 'items' => [['name' => 'Sách', 'quantity' => 2]]]);
        $service->submit($this->owner, $order, $order->revision);
        $service->review($this->sender, $order->fresh(), $order->fresh()->revision, 'approved', 'Đủ thông tin liên tỉnh');
        $task = $this->assign($this->sender, $this->sourceShipper, $order->fresh(), 'pickup');
        app(DispatchService::class)->pickup($this->sourceShipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Lấy đủ hai kiện tại shop'], 'proof.png');
        $this->inbound($this->sender, $this->sourceShipper, $task, $this->sourceLocation);

        return $order->fresh();
    }

    private function assign(User $actor, User $shipper, Order $order, string $type = 'delivery'): DeliveryTask
    {
        return app(DispatchService::class)->assign($actor, $order, ['revision' => $order->fresh()->revision, 'type' => $type,
            'shipper_id' => $shipper->id, 'reason' => 'Phân công theo kho phụ trách']);
    }

    private function inbound(User $clerk, User $shipper, DeliveryTask $task, WarehouseLocation $location): void
    {
        $service = app(WarehouseService::class);
        $doc = $service->prepare($shipper, $task->fresh(), 'inbound', 'Bàn giao về kho kiểm đếm');
        $service->confirm($clerk, $doc, ['location_id' => $location->id, 'reason' => 'Nhập đủ kiện tại kho',
            'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all()], null);
    }

    private function dispatchData(Order $order, ?Warehouse $destination = null): array
    {
        return ['revision' => $order->fresh()->revision, 'destination_warehouse_id' => ($destination ?? $this->destination)->id,
            'carrier' => 'Tài xế tuyến Bắc Nam', 'transport_reference' => '30A-12345 / chuyến 01', 'reason' => 'Chuyển kho theo tuyến liên tỉnh'];
    }

    private function receiptData(WarehouseTransfer $transfer, ?WarehouseLocation $location = null): array
    {
        return ['revision' => $transfer->order->fresh()->revision, 'location_id' => ($location ?? $this->destinationLocation)->id,
            'reason' => 'Kho nhận đã kiểm đếm thực tế', 'lines' => $transfer->lines()->whereNull('received_at')->get()
                ->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()];
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

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function outbound(User $clerk, User $shipper, Order $order, string $type = 'delivery'): DeliveryTask
    {
        $task = $this->assign($clerk, $shipper, $order, $type);
        $service = app(WarehouseService::class);
        $doc = $service->prepare($clerk, $task, 'outbound', 'Xuất đủ kiện cho shipper');
        $service->confirm($shipper, $doc, ['reason' => 'Shipper kiểm đếm nhận đủ', 'lines' => $doc->lines
            ->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null);

        return $task->fresh();
    }

    public function test_interprovince_pickup_transfer_receipt_and_destination_delivery_preserve_money_and_history(): void
    {
        $order = $this->stocked();
        $snapshot = $order->fee_snapshot;
        $this->invalid(fn () => $this->assign($this->sender, $this->sourceShipper, $order));
        $this->actingAs($this->sender)->get(route('logistics.order', $order))->assertOk()->assertSee('Xuất chuyển kho')->assertDontSee('Phân công nhiệm vụ');
        $transfer = app(WarehouseTransferService::class)->dispatch($this->sender, $order, $this->dispatchData($order));
        $this->assertSame('in_transit', $order->fresh()->status);
        $this->assertSame('transfer', $order->fresh()->custody_status);
        $this->assertSame(0, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->actingAs($this->receiver)->get(route('transfers.index'))->assertOk()->assertSee($transfer->code);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Kho nhận kiểm đếm');
        $this->get(route('logistics.order', $order))->assertForbidden();
        app(WarehouseTransferService::class)->receive($this->receiver, $transfer, $this->receiptData($transfer), null);
        $this->assertSame($this->destination->id, $order->fresh()->warehouse_id);
        $this->assertSame($this->source->id, $order->fresh()->origin_warehouse_id);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(2, $order->parcels()->where('holder_type', 'warehouse')->where('warehouse_id', $this->destination->id)->count());
        $this->actingAs($this->sender)->get(route('logistics.order', $order))->assertForbidden();
        $this->actingAs($this->receiver)->get(route('logistics.order', $order))->assertOk()->assertSee('Phân công nhiệm vụ');
        $this->invalid(fn () => $this->assign($this->receiver, $this->sourceShipper, $order));
        $task = $this->outbound($this->receiver, $this->destinationShipper, $order->fresh());
        app(DeliveryService::class)->record($this->destinationShipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'success',
            'receiver_name' => 'Khách liên tỉnh', 'actual_cod' => 500000, 'actual_fee' => (int) $order->shipping_fee, 'reason' => 'Giao đủ và thu tiền đúng'], $this->photo());
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($snapshot, $order->fresh()->fee_snapshot);
        $this->assertSame(['cod', 'shipping_fee'], $order->collections()->orderBy('id')->pluck('type')->all());
        $this->assertTrue($order->collections()->get()->every(fn ($row) => $row->warehouse_id === $this->destination->id));
        $this->assertSame(1, $order->histories()->where('event', 'transfer_received')->count());
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $order->id, 'event' => 'logistics.transfer_dispatched']);
        $tracking = app(PublicTrackingService::class)->track($order->tracking_code, '6789');
        $this->assertContains('Đang chuyển kho', array_column($tracking['events'], 'status'));
        $this->assertStringNotContainsString('30A-12345', json_encode($tracking));
    }

    public function test_missing_parcel_stays_in_transit_and_blocks_delivery_until_received_and_independently_resolved(): void
    {
        $order = $this->stocked();
        $service = app(WarehouseTransferService::class);
        $transfer = $service->dispatch($this->sender, $order, $this->dispatchData($order));
        $data = $this->receiptData($transfer);
        $missingId = array_key_last($data['lines']);
        $data['lines'][$missingId]['received'] = false;
        $this->invalid(fn () => $service->receive($this->receiver, $transfer, $data, null));
        $this->assertSame(0, $transfer->lines()->whereNotNull('received_at')->count());
        $service->receive($this->receiver, $transfer, $data, 'missing.png');
        $this->assertSame('partial', $transfer->fresh()->status);
        $this->assertSame('mixed', $order->fresh()->custody_status);
        $this->assertSame('in_transit', $order->fresh()->status);
        $this->assertSame(1, $order->parcels()->where('holder_type', 'transfer')->count());
        $this->invalid(fn () => $this->assign($this->sender, $this->sourceShipper, $order));
        $this->invalid(fn () => $service->receive($this->receiver, $transfer, $data, 'missing.png'));
        $service->receive($this->receiver, $transfer, $this->receiptData($transfer), null);
        $this->assertSame('at_warehouse', $order->fresh()->status);
        $difference = $order->discrepancies()->sole();
        $this->assertSame('open', $difference->status);
        $this->invalid(fn () => $this->assign($this->receiver, $this->destinationShipper, $order));
        $this->invalid(fn () => app(WarehouseService::class)->resolve($this->receiver, $difference, 'Tự xác nhận đã tìm đủ kiện'));
        app(WarehouseService::class)->resolve($this->admin, $difference, 'Xác minh kiện tìm lại, kho đích đã nhận đủ');
        $this->assertSame('assigned', $this->assign($this->receiver, $this->destinationShipper, $order)->status);
    }

    public function test_transfer_permissions_wrong_route_location_stale_revision_and_duplicate_receipt_are_rejected(): void
    {
        $order = $this->stocked();
        $service = app(WarehouseTransferService::class);
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $this->dispatchData($order, $this->warehouse('DN'))));
        $stale = $this->dispatchData($order);
        $stale['revision']--;
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $stale));
        $this->actingAs($this->owner)->post(route('transfers.store', $order), $this->dispatchData($order))->assertForbidden();
        $transfer = $service->dispatch($this->sender, $order, $this->dispatchData($order));
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $this->dispatchData($order)));
        $data = $this->receiptData($transfer);
        $this->sender->warehouses()->attach($this->destination);
        $this->actingAs($this->sender)->post(route('transfers.receive', $transfer), $data)->assertForbidden();
        $this->actingAs($this->destinationShipper)->get(route('transfers.show', $transfer))->assertForbidden();
        $outsider = $this->person('operations');
        $this->actingAs($outsider)->get(route('transfers.index'))->assertOk()->assertDontSee($transfer->code);
        $this->get(route('transfers.show', $transfer))->assertForbidden();
        $this->post(route('transfers.receive', $transfer), $data)->assertForbidden();
        $this->invalid(fn () => $service->receive($this->receiver, $transfer, $this->receiptData($transfer, $this->sourceLocation), null));
        $wrong = $data;
        $wrong['lines'][999999] = ['received' => true, 'condition' => 'good'];
        $this->invalid(fn () => $service->receive($this->receiver, $transfer, $wrong, null));
        $service->receive($this->receiver, $transfer, $data, null);
        $this->invalid(fn () => $service->receive($this->receiver, $transfer, $data, null));
        $this->assertSame(1, $order->histories()->where('event', 'transfer_received')->count());
        $this->assertSame(2, $transfer->lines()->whereNotNull('received_at')->count());
    }

    public function test_transfer_and_custody_roll_back_when_audit_fails(): void
    {
        $order = $this->stocked();
        $revision = $order->revision;
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(WarehouseTransferService::class)->dispatch($this->sender, $order, $this->dispatchData($order));
            $this->fail('Phải rollback khi audit thất bại.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame($revision, $order->fresh()->revision);
        $this->assertSame(0, $order->transfers()->count());
        $this->assertSame(2, $order->parcels()->where('holder_type', 'warehouse')->count());
    }

    public function test_damaged_receipt_holds_destination_dispatch_and_evidence_is_private(): void
    {
        $order = $this->stocked();
        $service = app(WarehouseTransferService::class);
        $transfer = $service->dispatch($this->sender, $order, $this->dispatchData($order));
        $data = $this->receiptData($transfer);
        $first = array_key_first($data['lines']);
        $data['lines'][$first]['condition'] = 'damaged';
        $data['evidence'] = $this->photo();
        $this->actingAs($this->receiver)->post(route('transfers.receive', $transfer), $data)->assertRedirect(route('transfers.show', $transfer))->assertSessionHasNoErrors();
        $line = $transfer->lines()->findOrFail($first);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('damaged', $line->item->condition);
        Storage::disk('local')->assertExists($line->evidence_path);
        $this->invalid(fn () => $this->assign($this->receiver, $this->destinationShipper, $order));
        $this->assertDatabaseHas('discrepancies', ['warehouse_transfer_id' => $transfer->id, 'type' => 'damaged', 'status' => 'open']);
        $this->get(route('transfers.evidence', [$transfer, $line]))->assertOk();
        $this->actingAs($this->owner)->get(route('transfers.evidence', [$transfer, $line]))->assertForbidden();
        $this->actingAs($this->person('operations'))->get(route('transfers.evidence', [$transfer, $line]))->assertForbidden();
    }

    public function test_pending_change_active_task_and_discrepancy_block_transfer_dispatch(): void
    {
        $order = $this->stocked();
        $task = $order->tasks()->create(['warehouse_id' => $this->source->id, 'shipper_id' => $this->sourceShipper->id,
            'created_by' => $this->sender->id, 'type' => 'delivery', 'reason' => 'Nhiệm vụ cũ chưa thu hồi']);
        $service = app(WarehouseTransferService::class);
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $this->dispatchData($order)));
        $task->update(['status' => 'cancelled', 'finished_at' => now()]);
        $change = $order->changeRequests()->create(['created_by' => $this->owner->id, 'type' => 'cancel', 'order_revision' => $order->revision,
            'reason' => 'Đề nghị cũ chưa giải quyết', 'before_values' => [], 'changes' => []]);
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $this->dispatchData($order)));
        $change->update(['status' => 'rejected']);
        $order->discrepancies()->create(['warehouse_id' => $this->source->id, 'warehouse_item_id' => $order->parcels()->value('id'),
            'created_by' => $this->sender->id, 'type' => 'damaged', 'severity' => 'high', 'reason' => 'Giữ để xác minh kiện hỏng']);
        $this->invalid(fn () => $service->dispatch($this->sender, $order, $this->dispatchData($order)));
        $this->assertSame(0, $order->transfers()->count());
        $this->assertSame('warehouse', $order->fresh()->custody_status);
    }

    public function test_database_allows_only_one_active_transfer_per_order(): void
    {
        $order = $this->stocked();
        $transfer = app(WarehouseTransferService::class)->dispatch($this->sender, $order, $this->dispatchData($order));
        $duplicate = $transfer->replicate(['active_order_id']);
        $duplicate->code = 'CK'.Str::upper((string) Str::ulid());
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    public function test_transfer_notification_reaches_destination_staff_and_rechecks_revoked_membership(): void
    {
        $order = $this->stocked();
        $transfer = app(WarehouseTransferService::class)->dispatch($this->sender, $order, $this->dispatchData($order));
        $log = AuditLog::where('event', 'logistics.transfer_dispatched')->where('subject_id', $order->id)->sole();
        $this->assertSame($transfer->id, $log->new_values['transfer']['id']);
        $this->assertSame('Tài xế tuyến Bắc Nam', $log->new_values['transfer']['carrier']);
        $dispatch = NotificationDispatch::where('audit_log_id', $log->id)->sole();
        $service = app(NotificationService::class);
        $service->deliver($dispatch->id);
        $service->deliver($dispatch->id);
        $notification = $this->receiver->notifications()->where('audit_log_id', $log->id)->sole();
        $this->assertSame('transfer', $notification->data['target_type']);
        $this->assertFalse($this->sender->notifications()->where('audit_log_id', $log->id)->exists());
        $this->assertFalse($this->owner->notifications()->where('audit_log_id', $log->id)->exists());
        $this->assertFalse($this->destinationShipper->notifications()->where('audit_log_id', $log->id)->exists());
        $this->receiver->warehouses()->detach();
        $this->actingAs($this->receiver)->get(route('transfers.show', $transfer))->assertForbidden();
    }

    public function test_interprovince_return_must_travel_back_to_original_warehouse_before_shipper_returns_to_sender(): void
    {
        $order = $this->stocked();
        $service = app(WarehouseTransferService::class);
        $transfer = $service->dispatch($this->sender, $order, $this->dispatchData($order));
        $service->receive($this->receiver, $transfer, $this->receiptData($transfer), null);
        app(DeliveryDecisionService::class)->decide($this->receiver, $order->fresh(), ['decision' => 'return',
            'shop_instruction' => 'Shop yêu cầu hoàn về địa chỉ gửi', 'reason' => 'Khách từ chối nhận, shop xác nhận hoàn']);
        $this->invalid(fn () => $this->assign($this->receiver, $this->destinationShipper, $order, 'return'));
        $return = $service->dispatch($this->receiver, $order->fresh(), $this->dispatchData($order, $this->source));
        $this->assertSame('return', $return->purpose);
        $service->receive($this->sender, $return, $this->receiptData($return, $this->sourceLocation), null);
        $task = $this->outbound($this->sender, $this->sourceShipper, $order->fresh(), 'return');
        app(DeliveryService::class)->record($this->sourceShipper, $task, ['client_token' => (string) Str::uuid(),
            'outcome' => 'success', 'receiver_name' => 'Chủ shop', 'actual_fee' => 0, 'reason' => 'Shop đã nhận lại đủ hai kiện'], $this->photo());
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame($this->source->id, $order->fresh()->warehouse_id);
        $this->assertSame(2, $order->transfers()->where('status', 'received')->count());
        $this->assertFalse($order->collections()->where('type', 'cod')->exists());
    }
}
