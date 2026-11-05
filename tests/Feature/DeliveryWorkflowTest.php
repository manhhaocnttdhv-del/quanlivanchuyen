<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\OrderService;
use App\Services\PublicTrackingService;
use App\Services\WarehouseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class DeliveryWorkflowTest extends DatabaseTestCase
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
        $this->dispatcher = $this->person('operations');
        $this->clerk = $this->person('operations');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        foreach ([$this->dispatcher, $this->clerk, $this->shipper] as $user) {
            $user->warehouses()->attach($this->depot);
        }
        $this->location = WarehouseLocation::create(['warehouse_id' => $this->depot->id, 'code' => 'A-01', 'name' => 'Kệ giao hàng']);
        $data = $this->feeData();
        foreach ($data['rules'] as &$rule) {
            $rule['return_fee'] = 15000;
        }
        $fees = app(FeeVersionService::class);
        $fees->publish($this->admin, $fees->save($this->admin, $data), 'Áp dụng biểu phí giao hoàn');
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function stocked(string $payer = 'recipient'): Order
    {
        $orders = app(OrderService::class);
        $order = $orders->saveDraft($this->owner, ['client_token' => (string) Str::uuid(), 'pickup_address_id' => $this->owner->shop->addresses()->value('id'),
            'warehouse_id' => $this->depot->id,
            'area_id' => Area::where('code', 'HN')->value('id'), 'recipient_name' => 'Khách nhận hàng', 'recipient_phone' => '0987656789', 'recipient_address' => '10 Đường thử nghiệm',
            'weight_grams' => 1000, 'parcel_count' => 2, 'service' => 'standard', 'cod_amount' => 500000, 'fee_payer' => $payer, 'items' => [['name' => 'Sách', 'quantity' => 2]]]);
        $orders->submit($this->owner, $order, $order->revision);
        $orders->review($this->admin, $order->fresh(), $order->fresh()->revision, 'approved', 'Đủ điều kiện tiếp nhận');
        $order->refresh();
        $task = $this->assign($order, 'pickup');
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Đã lấy đủ hai kiện'], 'proof.png');
        $this->backToWarehouse($task);

        return $order->fresh();
    }

    private function assign(Order $order, string $type = 'delivery'): DeliveryTask
    {
        return app(DispatchService::class)->assign($this->dispatcher, $order, ['revision' => $order->fresh()->revision, 'type' => $type, 'shipper_id' => $this->shipper->id, 'reason' => 'Phân công tuyến giao hoàn']);
    }

    private function out(Order $order, string $type = 'delivery'): DeliveryTask
    {
        $task = $this->assign($order, $type);
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->clerk, $task, 'outbound', 'Kho xuất đủ kiện cho shipper');
        $service->confirm($this->shipper, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all(), 'reason' => 'Shipper đã kiểm đếm đủ'], null);

        return $task->fresh();
    }

    private function backToWarehouse(DeliveryTask $task): void
    {
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->shipper, $task, 'inbound', 'Trả đủ kiện về kho');
        $service->confirm($this->clerk, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all(), 'location_id' => $this->location->id, 'reason' => 'Kho đã kiểm đếm đủ kiện'], 'proof.png');
    }

    private function success(DeliveryTask $task, array $overrides = []): array
    {
        return array_replace(['client_token' => (string) Str::uuid(), 'outcome' => 'success', 'receiver_name' => 'Người nhận thực tế', 'actual_cod' => 500000,
            'actual_fee' => $task->order->fee_payer === 'recipient' ? (int) $task->order->shipping_fee : 0, 'reason' => 'Bàn giao đủ hàng, đã đếm tiền'], $overrides);
    }

    private function failure(DeliveryTask $task, string $code = 'absent')
    {
        return app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => $code, 'reason' => 'Đã gọi khách nhiều lần, chưa nhận hàng'], in_array($code, ['lost', 'damaged']) ? $this->photo() : null);
    }

    private function decide(Order $order, string $decision = 'retry', array $extra = []): void
    {
        app(DeliveryDecisionService::class)->decide($this->dispatcher, $order, ['revision' => $order->fresh()->revision, 'decision' => $decision, 'reason' => 'Điều phối xác minh và xử lý', 'shop_instruction' => 'Shop đã xác nhận đồng ý'] + $extra);
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

    public function test_success_transfers_all_parcels_and_separates_cod_from_fee(): void
    {
        $task = $this->out($this->stocked());
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo());
        $order = $task->order->fresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('recipient', $order->custody_status);
        $this->assertNull($order->assigned_shipper_id);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(1, $order->delivery_attempt_count);
        $this->assertSame(2, $order->parcels()->where('holder_type', 'recipient')->whereNull('holder_user_id')->whereNull('warehouse_location_id')->count());
        $this->assertSame(['cod', 'shipping_fee'], $attempt->collections()->orderBy('id')->pluck('type')->all());
        $this->assertSame('500000.00', $attempt->collections()->where('type', 'cod')->value('amount'));
        $this->assertFalse($order->financial_hold);
        Storage::disk('local')->assertExists($attempt->proof->getRawOriginal('evidence_path'));
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'event' => 'delivery_succeeded']);
        $audit = AuditLog::where('event', 'delivery.collection_recorded')->firstOrFail();
        $this->assertSame('500000.00', $audit->new_values['amount']);
        $this->assertArrayNotHasKey('client_token', $audit->new_values);
    }

    public function test_exact_retry_is_idempotent_but_changed_payload_or_photo_is_rejected(): void
    {
        $task = $this->out($this->stocked());
        $data = $this->success($task);
        $service = app(DeliveryService::class);
        $attempt = $service->record($this->shipper, $task, $data, $this->photo());
        $history = $task->order->histories()->count();
        $again = $service->record($this->shipper, $task, $data, $this->photo());
        $this->assertSame($attempt->id, $again->id);
        $this->assertSame(1, $task->order->deliveryAttempts()->count());
        $this->assertSame(2, $task->order->collections()->count());
        $this->assertSame($history, $task->order->histories()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('delivery-evidence'));
        $this->invalid(fn () => $service->record($this->shipper, $task, array_replace($data, ['actual_cod' => 499999]), $this->photo()));
        $source = $this->photo();
        $other = UploadedFile::fake()->createWithContent('other.png', file_get_contents($source->getRealPath()).'different');
        $this->invalid(fn () => $service->record($this->shipper, $task, $data, $other));
        $this->invalid(fn () => $service->record($this->shipper, $task, $this->success($task), $this->photo()));
    }

    public function test_sender_pays_shipping_and_mismatch_needs_reason_and_financial_hold(): void
    {
        $task = $this->out($this->stocked('sender'));
        $data = $this->success($task, ['actual_cod' => 499000, 'actual_fee' => 2000]);
        $service = app(DeliveryService::class);
        $this->invalid(fn () => $service->record($this->shipper, $task, $data, $this->photo()));
        $attempt = $service->record($this->shipper, $task, $data + ['financial_reason' => 'Khách trả thiếu COD và thu nhầm phí'], $this->photo());
        $this->assertSame('0.00', $attempt->expected_fee);
        $this->assertTrue($task->order->fresh()->financial_hold);
        $this->assertSame(2, $attempt->collections()->where('financial_status', 'disputed')->count());
        $this->assertSame('delivered', $task->order->fresh()->status);
    }

    public function test_failure_counts_actual_attempt_and_requires_physical_return_before_retry(): void
    {
        $order = $this->stocked();
        $task = $this->out($order);
        $this->assertSame(0, $order->fresh()->delivery_attempt_count);
        $this->failure($task);
        $this->assertSame(1, $order->fresh()->delivery_attempt_count);
        $this->assertSame('delivery_failed', $order->fresh()->status);
        $this->assertSame('recall_pending', $task->fresh()->status);
        $this->assertSame('shipper', $order->fresh()->custody_status);
        $this->assertSame(0, $order->collections()->count());
        $this->invalid(fn () => $this->assign($order));
        $this->invalid(fn () => $this->decide($order));
        $this->backToWarehouse($task);
        $this->invalid(fn () => $this->assign($order));
        $this->decide($order);
        $this->invalid(fn () => $this->decide($order));
        $next = $this->assign($order);
        app(DispatchService::class)->recall($this->dispatcher, $next, 'Thay tuyến trước khi nhận kiện');
        $this->assertSame('assigned', $this->assign($order)->status);
        $this->assertSame(1, $order->fresh()->delivery_attempt_count);
    }

    public function test_three_attempt_limit_and_explicit_exception_grants_one_extra_attempt(): void
    {
        $order = $this->stocked();
        for ($number = 1; $number <= 3; $number++) {
            $task = $this->out($order);
            $this->failure($task);
            $this->backToWarehouse($task);
            if ($number < 3) {
                $this->decide($order);
            }
        }
        $this->invalid(fn () => $this->assign($order));
        $this->invalid(fn () => $this->decide($order));
        $this->decide($order, 'retry_exception');
        $this->assertSame(4, $order->fresh()->delivery_attempt_limit);
        $task = $this->out($order);
        $this->failure($task);
        $this->backToWarehouse($task);
        $this->invalid(fn () => $this->assign($order));
        $this->assertSame(4, $order->fresh()->delivery_attempt_count);
        $this->assertSame(3, $order->deliveryDecisions()->where('decision', 'retry')->count() + $order->deliveryDecisions()->where('decision', 'retry_exception')->count());
    }

    public function test_return_fee_is_frozen_and_success_creates_return_fee_only(): void
    {
        $order = $this->stocked();
        $task = $this->out($order);
        $this->failure($task, 'refused');
        $this->backToWarehouse($task);
        // Mô phỏng bảng giá đã thay đổi: hồ sơ hoàn vẫn lấy giá chốt trên vận đơn.
        DB::table('fee_rules')->where('id', $order->fee_rule_id)->update(['return_fee' => 99999]);
        $this->decide($order, 'return', ['fee_payment' => 'collect_on_return']);
        $return = $order->returnRecord()->firstOrFail();
        $this->assertSame('15000.00', $return->fee_amount);
        $this->assertNull($return->fee_charged_at);
        $task = $this->out($order, 'return');
        $data = ['client_token' => (string) Str::uuid(), 'outcome' => 'success', 'receiver_name' => 'Chủ shop', 'actual_fee' => 15000, 'reason' => 'Shop nhận đủ hai kiện hoàn'];
        $service = app(DeliveryService::class);
        $this->invalid(fn () => $service->record($this->shipper, $task, $data + ['actual_cod' => 1], $this->photo()));
        $attempt = $service->record($this->shipper, $task, $data, $this->photo());
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame('shop', $order->fresh()->custody_status);
        $this->assertSame(1, $order->fresh()->return_attempt_count);
        $this->assertNotNull($return->fresh()->fee_charged_at);
        $this->assertSame('0.00', $attempt->actual_cod);
        $this->assertSame(['return_fee'], $attempt->collections()->pluck('type')->all());
        $this->assertSame('15000.00', $attempt->collections()->first()->amount);
        $this->assertNotNull($order->fresh()->returned_at);
    }

    public function test_legacy_snapshot_waives_return_fee_and_reconciliation_collects_no_cash(): void
    {
        $order = $this->stocked();
        $snapshot = $order->fee_snapshot;
        unset($snapshot['return_fee']);
        $order->update(['fee_snapshot' => $snapshot]);
        $this->decide($order, 'return');
        $this->assertSame('legacy_waived', $order->returnRecord->fee_snapshot['source']);
        $task = $this->out($order, 'return');
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'success', 'receiver_name' => 'Chủ shop', 'actual_fee' => 0, 'reason' => 'Shop nhận đủ hàng hoàn'], $this->photo());
        $this->assertSame('0.00', $attempt->expected_fee);
        $this->assertSame('0.00', $attempt->collections()->first()->amount);
        $this->assertSame('0.00', $order->returnRecord->fee_amount);
    }

    public function test_return_failure_keeps_fee_uncharged_and_allows_another_return_after_receipt(): void
    {
        $order = $this->stocked();
        $this->decide($order, 'return');
        $task = $this->out($order, 'return');
        $this->failure($task);
        $this->assertSame('return_failed', $order->fresh()->status);
        $this->assertSame('failed', $order->returnRecord->status);
        $this->assertNull($order->returnRecord->fee_charged_at);
        $this->assertSame(0, $order->collections()->count());
        $this->backToWarehouse($task);
        $next = $this->out($order, 'return');
        $this->assertSame(1, $order->fresh()->return_attempt_count);
        $this->assertSame(1, $order->returnRecord()->count());
        $this->assertSame('returning', $next->order->fresh()->status);
    }

    public function test_missing_proof_receiver_negative_fractional_or_failure_money_is_rejected(): void
    {
        $task = $this->out($this->stocked());
        $service = app(DeliveryService::class);
        $this->invalid(fn () => $service->record($this->shipper, $task, $this->success($task), null));
        foreach ([['receiver_name' => ''], ['actual_cod' => -1], ['actual_cod' => '500000.01'], ['actual_fee' => '1e3'], ['actual_fee' => 10000000000000]] as $extra) {
            $this->invalid(fn () => $service->record($this->shipper, $task, $this->success($task, $extra), $this->photo()));
        }
        $this->invalid(fn () => $service->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => 'damaged', 'reason' => 'Hàng bị hỏng khi di chuyển'], null));
        $this->invalid(fn () => $service->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => 'absent', 'reason' => 'Khách chưa có nhà', 'actual_cod' => 1], null));
        $this->assertSame(0, $task->order->deliveryAttempts()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('delivery-evidence'));
    }

    public function test_damage_failure_creates_hold_and_success_cannot_bypass_existing_hold(): void
    {
        $task = $this->out($this->stocked());
        Discrepancy::create(['warehouse_id' => $this->depot->id, 'order_id' => $task->order_id, 'warehouse_item_id' => $task->order->parcels()->value('id'), 'created_by' => $this->clerk->id, 'type' => 'damaged', 'severity' => 'high', 'reason' => 'Phát hiện hàng hỏng sau xuất kho']);
        $this->invalid(fn () => app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo()));
        $attempt = $this->failure($task, 'damaged');
        $this->assertSame(2, $task->order->discrepancies()->where('status', 'open')->count());
        $this->assertSame(0, $attempt->collections()->count());
    }

    public function test_pending_handover_recall_and_missing_parcel_block_final_confirmation(): void
    {
        $task = $this->out($this->stocked());
        $item = $task->order->parcels()->first();
        $item->update(['holder_type' => 'warehouse', 'holder_user_id' => null]);
        $this->invalid(fn () => app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo()));
        $item->update(['holder_type' => 'shipper', 'holder_user_id' => $this->shipper->id]);
        $doc = app(WarehouseService::class)->prepare($this->shipper, $task, 'inbound', 'Đề nghị trả về kho');
        $this->invalid(fn () => app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo()));
        $doc->update(['status' => 'cancelled']);
        app(DispatchService::class)->recall($this->dispatcher, $task, 'Điều phối thu hồi hàng');
        $this->invalid(fn () => app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo()));
    }

    public function test_wrong_shipper_foreign_warehouse_and_shop_cannot_record_or_view_proofs(): void
    {
        $task = $this->out($this->stocked());
        $outsider = $this->person('shipper');
        $outsider->warehouses()->attach($this->depot);
        $this->actingAs($outsider)->post(route('delivery.record', $task), $this->success($task) + ['evidence' => $this->photo()])->assertForbidden();
        $this->actingAs($this->owner)->post(route('delivery.record', $task), $this->success($task) + ['evidence' => $this->photo()])->assertForbidden();
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo());
        $url = route('delivery.proof', $attempt);
        $this->actingAs($outsider)->get($url)->assertForbidden();
        $this->actingAs($this->shopOwner())->get($url)->assertForbidden();
        $financeAdmin = $this->person('admin');
        $this->actingAs($financeAdmin)->get($url)->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->shipper)->get($url)->assertOk();
        $this->shipper->warehouses()->detach($this->depot);
        $this->get($url)->assertForbidden();
    }

    public function test_collection_ledger_and_mobile_lists_are_scoped_and_render(): void
    {
        $task = $this->out($this->stocked());
        $this->actingAs($this->shipper)->get('/viec-cua-toi')->assertOk()->assertSee($task->order->tracking_code);
        $outsider = $this->person('shipper');
        $outsider->warehouses()->attach($this->depot);
        $this->actingAs($outsider)->get('/viec-cua-toi')->assertOk()->assertDontSee($task->order->tracking_code);
        app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo());
        $this->actingAs($this->shipper)->get('/viec-cua-toi?view=history')->assertOk()->assertSee($task->order->tracking_code);
        foreach ([$this->shipper, $this->owner, $this->dispatcher] as $user) {
            $this->actingAs($user)->get('/so-thu-giao-hang')->assertOk()->assertSee($task->order->tracking_code);
        }
        $this->actingAs($outsider)->get('/so-thu-giao-hang')->assertOk()->assertDontSee($task->order->tracking_code);
        $accountant = $this->person('operations');
        $this->actingAs($accountant)->get('/so-thu-giao-hang')->assertOk()->assertDontSee($task->order->tracking_code);
        $accountant->warehouses()->attach($this->depot);
        $this->get('/so-thu-giao-hang?type=cod')->assertOk()->assertSee($task->order->tracking_code);
        $this->get('/don-hang/'.$task->order_id)->assertOk();
        $this->actingAs($this->owner)->get('/don-hang/'.$task->order_id)->assertOk()->assertSee('Người nhận thực tế');
        $this->actingAs($this->dispatcher)->get('/van-hanh/don/'.$task->order_id)->assertOk()->assertSee('Thành công');
    }

    public function test_audit_failure_rolls_back_money_holder_attempt_and_uploaded_file(): void
    {
        $task = $this->out($this->stocked());
        $history = $task->order->histories()->count();
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(DeliveryService::class)->record($this->shipper, $task, $this->success($task), $this->photo());
            $this->fail('Phải rollback toàn bộ.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame('out_for_delivery', $task->order->fresh()->status);
        $this->assertSame('shipper', $task->order->fresh()->custody_status);
        $this->assertSame(0, $task->order->deliveryAttempts()->count());
        $this->assertSame(0, $task->order->collections()->count());
        $this->assertSame($history, $task->order->histories()->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('delivery-evidence'));
    }

    public function test_recorded_attempt_proof_collection_and_decision_are_append_only(): void
    {
        $order = $this->stocked();
        $this->decide($order, 'return');
        $decision = $order->deliveryDecisions()->first();
        $task = $this->out($order, 'return');
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'success', 'receiver_name' => 'Chủ shop', 'actual_fee' => 0, 'reason' => 'Shop nhận đủ hàng hoàn'], $this->photo());
        foreach ([$attempt, $attempt->proof, $attempt->collections()->first(), $decision] as $model) {
            try {
                $model->delete();
                $this->fail('Chứng từ gốc không được xóa.');
            } catch (\LogicException) {
                $this->assertTrue($model->fresh()->exists);
            }
            try {
                $model->updated_at = now()->addMinute();
                $model->save();
                $this->fail('Chứng từ gốc không được sửa.');
            } catch (\LogicException) {
                $this->assertNotNull($model->fresh());
            }
        }
    }

    public function test_tracking_exposes_failure_status_without_receiver_money_or_internal_reason(): void
    {
        $task = $this->out($this->stocked());
        $this->failure($task);
        $tracking = app(PublicTrackingService::class)->track($task->order->tracking_code, '6789');
        $this->assertSame('Giao thất bại', $tracking['status']);
        $text = json_encode($tracking, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Đã gọi khách', $text);
        $this->assertStringNotContainsString('500000', $text);
        $this->assertStringNotContainsString('0987656789', $text);
        $this->assertStringNotContainsString('proof', $text);
    }

    public function test_http_forms_record_success_replay_failure_and_enforce_decision_revision(): void
    {
        $task = $this->out($this->stocked());
        $this->actingAs($this->shipper)->get(route('logistics.show', $task))->assertOk()->assertSee('COD thực thu');
        $data = $this->success($task);
        $this->post(route('delivery.record', $task), $data + ['evidence' => $this->photo()])->assertRedirect(route('logistics.show', $task));
        $this->post(route('delivery.record', $task), $data + ['evidence' => $this->photo()])->assertRedirect(route('logistics.show', $task));
        $this->assertSame(2, $task->order->collections()->count());
        $task = $this->out($this->stocked());
        $this->post(route('delivery.record', $task), ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => 'absent', 'reason' => 'Khách chưa có nhà'])->assertSessionHasNoErrors();
        $this->backToWarehouse($task);
        $decision = ['decision' => 'retry', 'revision' => 1, 'shop_instruction' => 'Shop đồng ý giao lại', 'reason' => 'Khách hẹn ngày giao mới'];
        $this->actingAs($this->dispatcher)->post(route('delivery.decide', $task->order), $decision)->assertSessionHasErrors('revision');
        $decision['revision'] = $task->order->fresh()->revision;
        $this->post(route('delivery.decide', $task->order), $decision)->assertSessionHasNoErrors();
        $this->assertSame(2, $task->order->fresh()->retry_authorized_attempt);
    }

    public function test_scoped_dispatch_permission_is_not_enough_to_approve_attempt_exception(): void
    {
        $order = $this->stocked();
        $task = $this->out($order);
        $this->failure($task);
        $this->backToWarehouse($task);
        $order->refresh()->update(['delivery_attempt_limit' => 1]);
        $limited = User::factory()->create(['password' => 'TestPassword!2026'])->refresh();
        $limited->warehouses()->attach($this->depot);
        $role = Role::create(['name' => 'dispatch-test', 'label' => 'Chỉ phân công']);
        $role->permissions()->attach(Permission::where('name', 'logistics.dispatch')->value('id'));
        $limited->roles()->attach($role);
        $data = ['decision' => 'retry_exception', 'revision' => $order->revision, 'shop_instruction' => 'Shop đề nghị thêm một lượt', 'reason' => 'Khách hẹn nhận vào ngày mới'];
        $this->actingAs($limited)->post(route('delivery.decide', $order), $data)->assertForbidden();
        $foreign = $this->person('operations');
        $this->actingAs($foreign)->post(route('delivery.decide', $order), $data)->assertForbidden();
        $this->assertSame(1, $order->fresh()->delivery_attempt_limit);
    }

    public function test_reconciliation_return_fee_is_an_obligation_without_cash_and_money_boundaries_are_exact(): void
    {
        $order = $this->stocked();
        $this->decide($order, 'return');
        $task = $this->out($order, 'return');
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => (string) Str::uuid(), 'outcome' => 'success', 'receiver_name' => 'Chủ shop', 'actual_fee' => 0, 'reason' => 'Shop nhận hàng, phí hoàn tính đối soát'], $this->photo());
        $this->assertSame('15000.00', $order->returnRecord->fee_amount);
        $this->assertSame('0.00', $attempt->expected_fee);
        $this->assertSame('0.00', $attempt->collections()->first()->amount);
        $this->assertNotNull($order->returnRecord->fee_charged_at);
        try {
            $order->returnRecord->update(['fee_amount' => 99999]);
            $this->fail('Không được sửa phí hoàn đã chốt.');
        } catch (\LogicException) {
            $this->assertSame('15000.00', $order->returnRecord->fresh()->fee_amount);
        }
        $task = $this->out($this->stocked());
        $attempt = app(DeliveryService::class)->record($this->shipper, $task, $this->success($task, ['actual_cod' => '9999999999999', 'financial_reason' => 'Số thực thu ngoại lệ cần xác minh']), $this->photo());
        $this->assertSame('9999999999999.00', $attempt->collections()->where('type', 'cod')->value('amount'));
        $this->assertTrue($task->order->fresh()->financial_hold);
    }
}
