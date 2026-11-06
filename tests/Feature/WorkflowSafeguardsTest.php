<?php

namespace Tests\Feature;

use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AdjustmentService;
use App\Services\AuditService;
use App\Services\ComplaintService;
use App\Services\DeliveryService;
use App\Services\DispatchReadiness;
use App\Services\DispatchService;
use App\Services\IncidentService;
use App\Services\PublicTrackingService;
use App\Services\ShopService;
use App\Services\WarehouseService;
use App\Services\WarehouseTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class WorkflowSafeguardsTest extends DatabaseTestCase
{
    private User $admin;

    private User $reviewer;

    private User $sender;

    private User $receiver;

    private User $shipper;

    private User $owner;

    private Warehouse $source;

    private Warehouse $destination;

    private WarehouseLocation $location;

    private WarehouseLocation $destinationLocation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->person();
        $this->reviewer = $this->person();
        $this->sender = $this->person('operations');
        $this->receiver = $this->person('operations');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->source = $this->warehouse();
        $this->source->update(['code' => strtoupper($this->source->code)]);
        $this->destination = $this->warehouse('HCM');
        $this->sender->warehouses()->attach($this->source);
        $this->shipper->warehouses()->attach($this->source);
        $this->receiver->warehouses()->attach($this->destination);
        $this->location = WarehouseLocation::create(['warehouse_id' => $this->source->id, 'code' => 'A', 'name' => 'Source shelf']);
        $this->destinationLocation = WarehouseLocation::create(['warehouse_id' => $this->destination->id, 'code' => 'A', 'name' => 'Destination shelf']);
    }

    private function order(bool $interprovince = false, int $parcels = 1): Order
    {
        return Order::create(['shop_id' => $this->owner->shop->id, 'created_by' => $this->owner->id,
            'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
            'status' => 'approved', 'submitted_at' => now(), 'area_id' => $interprovince ? $this->destination->area_id : $this->source->area_id,
            'origin_area_id' => $this->source->area_id, 'warehouse_id' => $this->source->id, 'origin_warehouse_id' => $this->source->id,
            'recipient_name' => 'Recipient', 'recipient_phone' => '0987656789', 'recipient_address' => 'Delivery address',
            'sender_snapshot' => ['name' => 'Sender', 'phone' => '0987654321', 'address' => 'Pickup address', 'area' => 'Ha Noi'],
            'recipient_snapshot' => ['name' => 'Recipient', 'phone' => '0987656789', 'address' => 'Delivery address', 'area' => $interprovince ? 'HCM' : 'HN'], 'weight_grams' => 1000, 'parcel_count' => $parcels,
            'cod_amount' => 100000, 'shipping_fee' => 30000, 'fee_payer' => 'recipient']);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function assign(Order $order, string $type): DeliveryTask
    {
        return app(DispatchService::class)->assign($this->sender, $order->fresh(), ['revision' => $order->fresh()->revision,
            'type' => $type, 'shipper_id' => $this->shipper->id, 'reason' => 'Assign shipment for regression test']);
    }

    private function pickup(Order $order): DeliveryTask
    {
        $task = $this->assign($order, 'pickup');
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Collected shipment at sender'], 'proof.png');

        return $task->fresh();
    }

    private function inbound(DeliveryTask $task): void
    {
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->shipper->fresh(), $task->fresh(), 'inbound', 'Return all remaining parcels to warehouse');
        $service->confirm($this->sender, $doc, ['location_id' => $this->location->id, 'reason' => 'Warehouse counted actual parcels',
            'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good',
                'weight_grams' => intdiv(1000, $task->order->parcel_count)]])->all()], null);
    }

    private function stocked(bool $interprovince = false, int $parcels = 1): Order
    {
        $order = $this->order($interprovince, $parcels);
        $this->inbound($this->pickup($order));

        return $order->fresh();
    }

    private function transfer(Order $order)
    {
        return app(WarehouseTransferService::class)->dispatch($this->sender, $order->fresh(), ['revision' => $order->fresh()->revision,
            'destination_warehouse_id' => $this->destination->id, 'carrier' => 'Driver', 'transport_reference' => 'TEST-001', 'reason' => 'Transfer to destination branch']);
    }

    private function incident(Order $order, ?int $item = null, string $type = 'lost', ?User $actor = null): Incident
    {
        return app(IncidentService::class)->create($actor ?? $this->sender, $order->fresh(), ['client_token' => (string) Str::uuid(), 'type' => $type,
            'warehouse_item_id' => $item, 'title' => 'PRIVATE investigation title', 'description' => 'PRIVATE internal investigation details',
            'severity' => 'high', 'blocks_dispatch' => false, 'blocks_reconciliation' => false], $this->photo());
    }

    private function propose(Incident $incident, string $outcome = 'compensation', ?User $actor = null): void
    {
        app(IncidentService::class)->act($actor ?? $this->sender, $incident->fresh(), 'propose', ['revision' => $incident->fresh()->revision,
            'reason' => 'Verified facts and evidence', 'outcome' => $outcome, 'amount' => $outcome === 'no_financial' ? 0 : 100000,
            'conclusion' => 'Confirmed investigation and proposed resolution']);
    }

    private function approve(Incident $incident): void
    {
        app(IncidentService::class)->act($this->admin, $incident->fresh(), 'review', ['revision' => $incident->fresh()->revision,
            'decision' => 'approved', 'reason' => 'Independent reviewer verified evidence']);
    }

    private function invalid(callable $action, string $field = 'reason'): void
    {
        try {
            $action();
            $this->fail('Expected a business validation failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function userData(array $changes = []): array
    {
        return array_replace(['name' => $this->shipper->name, 'email' => $this->shipper->email, 'phone' => $this->shipper->phone,
            'status' => 'active', 'role_ids' => [Role::where('name', 'shipper')->value('id')], 'warehouse_ids' => [$this->source->id],
            'reason' => 'Update staff assignment after verification'], $changes);
    }

    private function warehouseData(array $changes = []): array
    {
        return array_replace($this->source->only(['code', 'name', 'address', 'area_id', 'is_active']), $changes);
    }

    public function test_compensated_loss_removes_inventory_and_never_releases_delivery(): void
    {
        $order = $this->stocked();
        $incident = $this->incident($order, $order->parcels()->sole()->id);
        $this->assertTrue($incident->blocks_dispatch);
        $this->propose($incident);
        $this->approve($incident);
        $adjustment = app(AdjustmentService::class)->create($this->admin, $order->fresh(), ['client_token' => (string) Str::uuid(),
            'reason' => 'Compensate confirmed lost parcel', 'kind' => 'compensation', 'amount' => 100000, 'incident_id' => $incident->id], $this->photo());
        app(AdjustmentService::class)->review($this->reviewer, $adjustment, 'approve', ['revision' => $adjustment->fresh()->revision, 'reason' => 'Second finance approver']);
        $item = $order->parcels()->sole();
        $this->assertSame('lost', $order->fresh()->status);
        $this->assertSame('lost', $item->holder_type);
        $this->assertSame('lost', $item->condition);
        $this->assertNull($item->holder_user_id);
        $this->assertNull($item->warehouse_location_id);
        $this->assertSame('processed', $incident->fresh()->financial_status);
        $this->assertTrue($incident->fresh()->blocks_dispatch);
        $this->assertFalse($incident->fresh()->blocks_reconciliation);
        $this->assertFalse(app(DispatchReadiness::class)->inspect($order->fresh())['canAssign']);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        $this->assertSame(0, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->assertSame('lost', $order->histories()->first()->after_values['parcels'][0]['holder_type']);
        $tracking = app(PublicTrackingService::class)->track($order->tracking_code, '6789');
        $this->assertSame(Order::STATUSES['lost'], $tracking['status']);
        $this->assertContains(Order::STATUSES['lost'], array_column($tracking['events'], 'status'));
        $this->assertStringNotContainsString('PRIVATE', json_encode($tracking));
    }

    public function test_partial_shipper_loss_recalls_remaining_goods_and_cancels_stale_handover(): void
    {
        $order = $this->order(parcels: 2);
        $task = $this->pickup($order);
        $pending = app(WarehouseService::class)->prepare($this->shipper, $task, 'inbound', 'Pending receipt before loss approval');
        $incident = $this->incident($order, $order->parcels()->firstOrFail()->id);
        $this->propose($incident);
        $this->approve($incident);
        $this->assertSame('recall_pending', $task->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(1, $order->parcels()->where('holder_type', 'shipper')->count());
        $this->inbound($task);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('at_warehouse', $order->fresh()->status);
        $this->assertSame(1, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->assertSame(1, $order->parcels()->where('holder_type', 'lost')->count());
        $this->assertFalse($order->discrepancies()->where('type', 'weight')->exists());
        $this->invalid(fn () => $this->assign($order, 'delivery'));
    }

    public function test_transfer_can_receive_remaining_parcels_after_independently_confirmed_loss(): void
    {
        $order = $this->stocked(true, 2);
        $transfer = $this->transfer($order);
        $incident = $this->incident($order, $order->parcels()->firstOrFail()->id);
        $this->propose($incident);
        $this->approve($incident);
        $this->assertSame('partial', $transfer->fresh()->status);
        $line = $transfer->lines()->whereNull('condition')->sole();
        app(WarehouseTransferService::class)->receive($this->receiver, $transfer, ['revision' => $order->fresh()->revision,
            'location_id' => $this->destinationLocation->id, 'reason' => 'Receive only the remaining actual parcel',
            'lines' => [$line->id => ['received' => true, 'condition' => 'good']]], null);
        $this->assertSame('closed_loss', $transfer->fresh()->status);
        $this->assertSame($this->destination->id, $order->fresh()->warehouse_id);
        $this->assertSame(1, $transfer->lines()->where('condition', 'lost')->whereNull('received_at')->count());
        $this->assertSame(1, $transfer->lines()->whereNotNull('received_at')->count());
        $this->assertSame(1, $order->parcels()->where('holder_type', 'warehouse')->count());
        $this->assertSame('transfer_closed_loss', $order->histories()->first()->event);
        $this->assertFalse($order->histories()->where('event', 'transfer_received')->exists());
        $this->actingAs($this->receiver)->get(route('transfers.show', $transfer))->assertOk()->assertSee('Đã xác nhận mất')->assertDontSee('Kho nhận kiểm đếm');
        $this->assertFalse(app(DispatchReadiness::class)->inspect($order->fresh())['canAssign']);
    }

    public function test_full_loss_finishes_outstanding_task_without_inventing_a_receipt(): void
    {
        $order = $this->order();
        $task = $this->pickup($order);
        $incident = $this->incident($order);
        $this->propose($incident);
        $this->approve($incident);
        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->finished_at);
        $this->assertSame('lost', $order->fresh()->custody_status);
        $this->assertNull($order->fresh()->assigned_shipper_id);
        $this->assertFalse($task->handovers()->exists());
        $this->assertFalse($order->collections()->exists());
    }

    public function test_full_transfer_loss_closes_the_trip_without_inventing_received_parcels(): void
    {
        $order = $this->stocked(true, 2);
        $transfer = $this->transfer($order);
        $incident = $this->incident($order);
        $this->propose($incident);
        $this->approve($incident);
        $this->assertSame('closed_loss', $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->received_at);
        $this->assertSame(2, $transfer->lines()->where('condition', 'lost')->whereNull('received_at')->count());
        $this->assertSame('lost', $order->fresh()->status);
        $this->actingAs($this->receiver)->get(route('transfers.show', $transfer))->assertOk()->assertDontSee('Kho nhận kiểm đếm');
        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertOk()->assertSee('Vận đơn dừng vận chuyển');
    }

    public function test_a_second_incident_cannot_confirm_loss_again_for_the_same_parcels(): void
    {
        $order = $this->stocked();
        $incident = $this->incident($order);
        $this->propose($incident);
        $this->approve($incident);
        $duplicate = $this->incident($order);
        $this->propose($duplicate);
        $this->invalid(fn () => $this->approve($duplicate));
        $this->assertSame('in_review', $duplicate->fresh()->status);
        $this->assertNull($duplicate->fresh()->approved_action_id);
        $this->assertSame(1, $order->parcels()->where('holder_type', 'lost')->count());
    }

    public function test_loss_cannot_be_cleared_without_actual_warehouse_recovery(): void
    {
        $order = $this->order();
        $task = $this->pickup($order);
        $incident = $this->incident($order);
        $this->propose($incident, 'no_financial');
        $this->invalid(fn () => $this->approve($incident));
        $this->assertSame('in_review', $incident->fresh()->status);
        $this->assertTrue($incident->fresh()->blocks_dispatch);
        $this->inbound($task);
        $this->approve($incident);
        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertFalse($incident->fresh()->blocks_dispatch);
        $this->assertTrue(app(DispatchReadiness::class)->inspect($order->fresh())['canAssign']);
    }

    public function test_legacy_compensated_loss_cannot_dispatch_even_with_old_hold_flag(): void
    {
        $order = $this->stocked();
        $incident = $this->incident($order);
        $this->propose($incident);
        $this->approve($incident);
        $incident->update(['blocks_dispatch' => false]);
        $order->update(['status' => 'at_warehouse', 'custody_status' => 'warehouse']);
        $order->parcels()->update(['holder_type' => 'warehouse', 'condition' => 'good']);
        $this->assertTrue($incident->fresh()->isDispatchBlocked());
        $this->actingAs($this->sender)->get(route('incidents.show', $incident))->assertOk()->assertSee('Khóa giao / hoàn')->assertDontSee('Không khóa giao');
        $this->get(route('incidents.index'))->assertOk()->assertSee('Khóa giao / hoàn');
        $this->assertFalse(app(DispatchReadiness::class)->inspect($order->fresh())['canAssign']);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
    }

    public function test_loss_custody_and_approval_roll_back_when_audit_fails(): void
    {
        $order = $this->stocked();
        $incident = $this->incident($order);
        $this->propose($incident);
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->approve($incident);
            $this->fail('Approval must roll back');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('in_review', $incident->fresh()->status);
        $this->assertNull($incident->fresh()->approved_action_id);
        $this->assertSame('warehouse', $order->parcels()->sole()->holder_type);
        $this->assertSame('at_warehouse', $order->fresh()->status);
    }

    public function test_staff_changes_preserve_custody_scope_until_handover_is_complete(): void
    {
        $order = $this->order();
        $task = $this->pickup($order);
        foreach ([['status' => 'inactive'], ['role_ids' => [Role::where('name', 'operations')->value('id')]], ['warehouse_ids' => [$this->destination->id]]] as $changes) {
            $this->actingAs($this->admin)->put(route('users.update', $this->shipper), $this->userData($changes))->assertSessionHasErrors();
            $this->assertSame('active', $this->shipper->fresh()->status);
            $this->assertTrue($this->shipper->fresh()->hasRole('shipper'));
            $this->assertSame([$this->source->id], $this->shipper->fresh()->warehouses->modelKeys());
            $this->assertSame(1, $this->shipper->fresh()->auth_version);
        }
        $this->inbound($task);
        $this->actingAs($this->admin)->put(route('users.update', $this->shipper), $this->userData(['warehouse_ids' => [$this->destination->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$this->destination->id], $this->shipper->fresh()->warehouses->modelKeys());
    }

    public function test_assigned_task_must_be_recalled_before_removing_warehouse_scope(): void
    {
        $order = $this->order();
        $task = $this->assign($order, 'pickup');
        $this->actingAs($this->admin)->put(route('users.update', $this->shipper), $this->userData(['warehouse_ids' => [$this->destination->id]]))->assertSessionHasErrors('warehouse_ids');
        app(DispatchService::class)->recall($this->sender, $task, 'Recall uncollected task before staff transfer');
        $this->put(route('users.update', $this->shipper), $this->userData(['warehouse_ids' => [$this->destination->id]]))->assertSessionHasNoErrors();
    }

    public function test_legacy_stranded_shipper_can_restore_a_closed_warehouse_for_inbound_recovery(): void
    {
        $order = $this->order();
        $task = $this->pickup($order);
        $this->source->update(['is_active' => false]);
        $this->shipper->warehouses()->sync([$this->destination->id]);
        $this->actingAs($this->admin)->get(route('users.edit', $this->shipper))->assertOk()
            ->assertViewHas('warehouses', fn ($warehouses) => $warehouses->contains('id', $this->source->id));
        $this->put(route('users.update', $this->shipper), $this->userData())->assertSessionHasNoErrors();
        $this->inbound($task);
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_warehouse_geography_cannot_rewrite_existing_shipment_routes(): void
    {
        $order = $this->stocked(true);
        $this->actingAs($this->admin)->put(route('warehouses.update', $this->source), $this->warehouseData(['area_id' => $this->destination->area_id]))->assertSessionHasErrors('area_id');
        $this->assertSame($order->origin_area_id, $this->source->fresh()->area_id);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        $this->put(route('warehouses.update', $this->source), $this->warehouseData(['is_active' => false]))->assertSessionHasNoErrors();
        $unused = $this->warehouse();
        $data = $unused->only(['code', 'name', 'address', 'area_id', 'is_active']);
        $data['code'] = strtoupper($data['code']);
        $data['area_id'] = $this->destination->area_id;
        $this->put(route('warehouses.update', $unused), $data)->assertSessionHasNoErrors();
        $this->assertSame($this->destination->area_id, $unused->fresh()->area_id);
    }

    public function test_foreign_branch_cannot_list_view_or_suspend_a_shop(): void
    {
        $foreignOwner = $this->shopOwner(false);
        $foreignOwner->shop->update(['name' => 'Foreign branch private shop', 'area_id' => $this->destination->area_id]);
        $shop = $foreignOwner->shop->fresh();
        $this->actingAs($this->sender)->get(route('shops.index'))->assertOk()->assertDontSee($shop->name);
        $this->get(route('shops.show', $shop))->assertForbidden();
        $this->post(route('shops.review', $shop), ['status' => 'suspended', 'reason' => 'Attempt outside assigned branch'])->assertForbidden();
        $this->assertSame('pending', $shop->fresh()->status);
        try {
            app(ShopService::class)->review($this->sender, $shop, ['status' => 'approved', 'reason' => 'Direct service bypass attempt']);
            $this->fail('Service must enforce branch authorization');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $shop->fresh()->status);
        }
        $this->actingAs($this->receiver)->post(route('shops.review', $shop), ['status' => 'approved', 'reason' => 'Correct branch reviewed sender'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('shops.show', $shop))->assertOk();
        $this->actingAs($foreignOwner)->get(route('shop.profile'))->assertOk();
    }

    public function test_origin_complaint_can_link_destination_evidence_without_reading_private_case(): void
    {
        $order = $this->stocked(true);
        $complaint = app(ComplaintService::class)->create($this->owner, $order, ['client_token' => (string) Str::uuid(),
            'type' => 'money', 'title' => 'Verify shipment fee', 'description' => 'Customer requests shipment fee verification']);
        $transfer = $this->transfer($order);
        app(WarehouseTransferService::class)->receive($this->receiver, $transfer, ['revision' => $order->fresh()->revision,
            'location_id' => $this->destinationLocation->id, 'reason' => 'Destination received entire shipment',
            'lines' => $transfer->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null);
        $incident = $this->incident($order, type: 'money', actor: $this->receiver);
        $other = $this->incident($this->order(), type: 'money');
        $this->actingAs($this->sender)->get(route('complaints.show', $complaint))->assertOk()->assertSee($incident->code)->assertDontSee('PRIVATE');
        $this->get(route('incidents.show', $incident))->assertForbidden();
        $attachment = $incident->actions()->first()->attachments()->sole();
        $this->get(route('cases.attachment', $attachment))->assertForbidden();
        $this->post(route('complaints.action', [$complaint, 'link']), ['revision' => $complaint->fresh()->revision, 'incident_id' => $other->id,
            'reason' => 'Reject references from another shipment'])->assertSessionHasErrors('incident_id');
        $this->post(route('complaints.action', [$complaint, 'link']), ['revision' => $complaint->fresh()->revision, 'incident_id' => $incident->id,
            'reason' => 'Link verified investigation for this shipment'])->assertSessionHasNoErrors();
        $this->get(route('complaints.show', $complaint))->assertOk()->assertSee($incident->code)->assertDontSee('href="'.route('incidents.show', $incident).'"', false)->assertDontSee('PRIVATE');
        $this->actingAs($this->receiver)->get(route('complaints.show', $complaint))->assertForbidden();
        $this->propose($incident, 'no_financial', $this->receiver);
        $this->approve($incident);
        $this->actingAs($this->sender)->post(route('complaints.action', [$complaint, 'close']), ['revision' => $complaint->fresh()->revision,
            'resolution' => 'Fee verified and response sent to customer'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $complaint->fresh()->status);
        $this->assertSame($this->source->id, $complaint->fresh()->warehouse_id);
    }

    public function test_closure_blocks_new_outbound_but_allows_already_issued_delivery_to_finish(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $this->actingAs($this->admin)->put(route('warehouses.update', $this->source), $this->warehouseData(['is_active' => false]))->assertSessionHasNoErrors();
        $this->actingAs($this->sender)->post(route('logistics.prepare', $task), ['direction' => 'outbound', 'reason' => 'Do not issue goods from a closed warehouse'])->assertSessionHasErrors('reason');
        $this->get(route('logistics.show', $task))->assertOk()->assertDontSee('Lập phiếu xuất');
        $this->source->refresh()->update(['is_active' => true]);
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->sender, $task, 'outbound', 'Prepare before area closure');
        $this->source->area->update(['is_active' => false]);
        $input = ['reason' => 'Receiver verifies actual issued goods', 'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()];
        $this->actingAs($this->shipper)->post(route('logistics.confirm', $doc), $input)->assertSessionHasErrors('reason');
        $this->get(route('logistics.handover', $doc))->assertOk()->assertDontSee('Xác nhận thực nhận');
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->source->area->update(['is_active' => true]);
        $service->confirm($this->shipper, $doc, $input, null);
        $this->source->update(['is_active' => false]);
        app(DeliveryService::class)->record($this->shipper, $task->fresh(), ['client_token' => (string) Str::uuid(), 'outcome' => 'success',
            'receiver_name' => 'Recipient', 'actual_cod' => 100000, 'actual_fee' => 30000, 'reason' => 'Finish goods issued before closure'], $this->photo());
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(2, $order->collections()->count());
    }

    public function test_closed_warehouse_cannot_assign_or_collect_but_can_record_a_failed_pickup(): void
    {
        $order = $this->order();
        $task = $this->assign($order, 'pickup');
        $this->source->update(['is_active' => false]);
        $this->actingAs($this->shipper)->get(route('logistics.show', $task))->assertOk()->assertDontSee('Lấy đủ hàng')->assertSee('Không lấy được');
        $this->invalid(fn () => $this->assign($this->order(), 'pickup'));
        $this->invalid(fn () => app(DispatchService::class)->pickup($this->shipper, $task, [
            'successful' => true, 'condition' => 'good', 'reason' => 'Cannot collect into a closed warehouse',
        ], 'proof.png'), 'warehouse_id');
        $this->assertFalse($order->parcels()->exists());
        $this->assertFalse($task->pickupAttempt()->exists());
        app(DispatchService::class)->pickup($this->shipper, $task, [
            'successful' => false, 'condition' => 'good', 'reason' => 'Warehouse closed before collection',
        ], 'proof.png');
        $this->assertSame('failed', $task->fresh()->status);
        $this->assertFalse($order->parcels()->exists());
    }

    public function test_closed_destination_can_receive_a_transfer_already_dispatched(): void
    {
        $order = $this->stocked(true);
        $transfer = $this->transfer($order);
        $this->destination->update(['is_active' => false]);
        app(WarehouseTransferService::class)->receive($this->receiver, $transfer, [
            'revision' => $order->fresh()->revision, 'location_id' => $this->destinationLocation->id,
            'reason' => 'Accept actual goods dispatched before closure',
            'lines' => $transfer->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all(),
        ], null);
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame($this->destination->id, $order->fresh()->warehouse_id);
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->assertFalse(app(DispatchReadiness::class)->inspect($order->fresh())['canAssign']);
    }
}
