<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\DeliveryTask;
use App\Models\Discrepancy;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\ComplaintService;
use App\Services\DeliveryDecisionService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\IncidentService;
use App\Services\WarehouseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class CaseWorkflowTest extends DatabaseTestCase
{
    private User $admin;

    private User $dispatcher;

    private User $support;

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
        $this->support = $this->person('support');
        $this->clerk = $this->person('warehouse');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        foreach ([$this->dispatcher, $this->support, $this->clerk, $this->shipper] as $user) {
            $user->warehouses()->attach($this->depot);
        }
        $this->location = WarehouseLocation::create(['warehouse_id' => $this->depot->id, 'code' => 'A-01', 'name' => 'Kệ kiểm thử']);
    }

    private function order(): Order
    {
        return Order::create(['shop_id' => $this->owner->shop->id, 'created_by' => $this->owner->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()),
            'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'status' => 'approved', 'submitted_at' => now(),
            'area_id' => $this->depot->area_id, 'origin_area_id' => $this->depot->area_id, 'warehouse_id' => $this->depot->id,
            'recipient_name' => 'Khách kiểm thử', 'recipient_phone' => '0987656789', 'recipient_address' => '10 Đường kiểm thử',
            'weight_grams' => 1000, 'parcel_count' => 2, 'cod_amount' => 500000, 'shipping_fee' => 30000, 'fee_payer' => 'recipient']);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function incidentData(array $changes = []): array
    {
        return array_replace(['client_token' => (string) Str::uuid(), 'title' => 'Xác minh thông tin vận chuyển', 'description' => 'Shop phản ánh thông tin cần kiểm tra lại.',
            'type' => 'information', 'severity' => 'high', 'blocks_dispatch' => true, 'blocks_reconciliation' => false], $changes);
    }

    private function incident(Order $order, array $changes = []): Incident
    {
        $data = $this->incidentData($changes);

        return app(IncidentService::class)->create($this->support, $order, $data, in_array($data['type'], ['lost', 'damaged', 'money']) ? $this->photo() : null);
    }

    private function incidentAct(Incident $incident, string $operation, array $data = [], ?User $actor = null, ?UploadedFile $file = null): void
    {
        app(IncidentService::class)->act($actor ?? $this->support, $incident->fresh(), $operation,
            $data + ['revision' => $incident->fresh()->revision, 'reason' => 'Đã xác minh căn cứ cùng các bên liên quan'], $file);
        $incident->refresh();
    }

    private function conclude(Incident $incident, string $outcome = 'no_financial', string $amount = '0'): void
    {
        $this->incidentAct($incident, 'propose', ['outcome' => $outcome, 'amount' => $amount, 'conclusion' => 'Đã kiểm tra chứng từ và thống nhất phương án xử lý.'], file: $outcome === 'no_financial' ? null : $this->photo());
        $this->incidentAct($incident, 'review', ['decision' => 'approved'], $this->dispatcher);
    }

    private function complaint(Order $order, array $changes = []): Complaint
    {
        return app(ComplaintService::class)->create($this->owner, $order, $changes + ['client_token' => (string) Str::uuid(), 'type' => 'delay',
            'title' => 'Yêu cầu kiểm tra việc giao hàng', 'description' => 'Shop đề nghị kiểm tra và phản hồi tình hình giao hàng.']);
    }

    private function complaintAct(Complaint $complaint, string $operation, array $data = [], ?User $actor = null, ?UploadedFile $file = null): void
    {
        app(ComplaintService::class)->act($actor ?? $this->support, $complaint->fresh(), $operation, $data + ['revision' => $complaint->fresh()->revision], $file);
        $complaint->refresh();
    }

    private function invalid(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Thao tác phải bị từ chối.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
            if ($field) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    private function assign(Order $order, string $type = 'pickup'): DeliveryTask
    {
        return app(DispatchService::class)->assign($this->dispatcher, $order, ['revision' => $order->fresh()->revision, 'type' => $type, 'shipper_id' => $this->shipper->id, 'reason' => 'Phân công tuyến kiểm thử sự cố']);
    }

    private function pickup(Order $order): DeliveryTask
    {
        $task = $this->assign($order);
        app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Đã nhận đủ hàng từ shop'], 'proof.png');

        return $task->fresh();
    }

    private function inbound(DeliveryTask $task): void
    {
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->shipper, $task, 'inbound', 'Đưa hàng về kho xác minh');
        $service->confirm($this->clerk, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all(),
            'location_id' => $this->location->id, 'reason' => 'Kho đếm đủ và bảo quản kiện'], null);
    }

    private function stocked(): Order
    {
        $order = $this->order();
        $this->inbound($this->pickup($order));

        return $order->fresh();
    }

    public function test_incident_create_validates_evidence_and_replay_without_duplicating_history(): void
    {
        $order = $this->order();
        $data = $this->incidentData(['type' => 'money']);
        $this->actingAs($this->support)->post(route('incidents.store', $order), $data)->assertSessionHasErrors('evidence');
        $this->post(route('incidents.store', $order), $data + ['evidence' => $this->photo()])->assertSessionHasNoErrors();
        $incident = $order->incidents()->sole();
        $this->assertTrue($incident->blocks_reconciliation);
        $this->assertFalse($order->fresh()->financial_hold);
        $this->assertTrue($order->fresh()->reconciliationBlocked());
        $this->post(route('incidents.store', $order), $data + ['evidence' => $this->photo()])->assertRedirect(route('incidents.show', $incident));
        $this->post(route('incidents.store', $order), array_replace($data, ['title' => 'Nội dung đã thay đổi', 'evidence' => $this->photo()]))->assertSessionHasErrors('client_token');
        $this->assertSame(1, $order->incidents()->count());
        $this->assertSame(1, $order->histories()->where('event', 'incident_opened')->count());
        $this->assertSame(1, Attachment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['event' => 'incident.created', 'subject_id' => $incident->id]);
    }

    public function test_references_cannot_point_to_parcels_tasks_or_collections_of_another_order(): void
    {
        $other = $this->order();
        $task = $this->pickup($other);
        $order = $this->order();
        foreach (['warehouse_item_id' => $other->parcels()->firstOrFail()->id, 'delivery_task_id' => $task->id, 'delivery_collection_id' => PHP_INT_MAX, 'discrepancy_id' => PHP_INT_MAX] as $field => $id) {
            $this->invalid(fn () => $this->incident($order, [$field => $id]), $field);
        }
        $this->assertSame(0, $order->incidents()->count());
    }

    public function test_cases_are_scoped_by_role_warehouse_shop_and_current_membership(): void
    {
        $order = $this->order();
        $incident = $this->incident($order);
        $complaint = $this->complaint($order);
        $foreignSupport = $this->person('support');
        $this->actingAs($foreignSupport)->get(route('incidents.show', $incident))->assertForbidden();
        $this->get(route('complaints.show', $complaint))->assertForbidden();
        $this->get(route('incidents.index'))->assertOk()->assertDontSee($incident->code);
        $this->get(route('complaints.index'))->assertOk()->assertDontSee($complaint->code);
        $this->actingAs($this->owner)->get(route('incidents.show', $incident))->assertForbidden();
        $this->actingAs($this->shopOwner())->get(route('complaints.show', $complaint))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('complaints.show', $complaint))->assertForbidden();
        $this->support->warehouses()->detach();
        $this->actingAs($this->support)->post(route('incidents.action', [$incident, 'note']), ['revision' => 1, 'reason' => 'Quyền kho đã bị thu hồi'])->assertForbidden();
        $this->get(route('complaints.show', $complaint))->assertForbidden();
    }

    public function test_shipper_can_report_only_an_active_assigned_order_and_cannot_propose_or_approve(): void
    {
        $order = $this->order();
        $this->actingAs($this->shipper)->post(route('incidents.store', $order), $this->incidentData())->assertForbidden();
        $this->assign($order);
        $this->post(route('incidents.store', $order), $this->incidentData())->assertSessionHasNoErrors();
        $incident = $order->incidents()->sole();
        $this->get(route('incidents.show', $incident))->assertOk();
        $this->post(route('incidents.action', [$incident, 'propose']), ['revision' => 1])->assertForbidden();
        $this->post(route('incidents.action', [$incident, 'review']), ['revision' => 1])->assertForbidden();
        $this->actingAs($this->person('shipper'))->get(route('incidents.show', $incident))->assertForbidden();
    }

    public function test_financial_proposal_requires_evidence_positive_integer_amount_and_separate_reviewer(): void
    {
        $incident = $this->incident($this->order());
        $proposal = ['outcome' => 'compensation', 'amount' => '9999999999999', 'conclusion' => 'Đề xuất bồi thường theo chứng từ đã xác minh'];
        $this->invalid(fn () => $this->incidentAct($incident, 'propose', $proposal), 'evidence');
        $this->invalid(fn () => $this->incidentAct($incident, 'propose', array_replace($proposal, ['amount' => 0]), file: $this->photo()), 'amount');
        $this->invalid(fn () => $this->incidentAct($incident, 'propose', array_replace($proposal, ['amount' => '1.25']), file: $this->photo()), 'amount');
        $this->incidentAct($incident, 'propose', $proposal, $this->admin, $this->photo());
        $this->assertSame('9999999999999.00', $incident->proposal->proposed_amount);
        $this->assertTrue($incident->blocks_reconciliation);
        $this->invalid(fn () => $this->incidentAct($incident, 'review', ['decision' => 'approved'], $this->admin));
        // Nguoi lap cung khong duoc duyet, du duoc cap vai tro admin sau do.
        $this->support->roles()->attach($this->admin->roles()->firstOrFail());
        $this->support->unsetRelation('roles');
        $this->invalid(fn () => $this->incidentAct($incident, 'review', ['decision' => 'approved']));
        $this->incidentAct($incident, 'review', ['decision' => 'approved'], $this->dispatcher);
        $this->assertSame('9999999999999.00', $incident->approvedAction->proposed_amount);
        $this->assertSame('pending_finance', $incident->financial_status);
        $this->assertSame('9999999999999.00', AuditLog::where('event', 'incident.approved')->sole()->new_values['proposed_amount']);
    }

    public function test_rejected_proposal_preserves_evidence_and_holds_then_can_be_resubmitted(): void
    {
        $incident = $this->incident($this->order());
        $this->incidentAct($incident, 'propose', ['outcome' => 'no_financial', 'amount' => 0, 'conclusion' => 'Thông tin cần xác minh thêm với kho và shop']);
        $proposalId = $incident->proposal_action_id;
        $this->incidentAct($incident, 'review', ['decision' => 'rejected'], $this->dispatcher);
        $this->assertSame('open', $incident->status);
        $this->assertNull($incident->proposal_action_id);
        $this->assertTrue($incident->blocks_dispatch);
        $this->assertDatabaseHas('incident_actions', ['id' => $proposalId, 'type' => 'proposed']);
        $this->invalid(fn () => $this->incidentAct($incident, 'note', ['revision' => 1]), 'revision');
        $this->conclude($incident);
        $this->assertSame('closed', $incident->status);
        $this->assertFalse($incident->blocks_dispatch);
        $this->invalid(fn () => $this->incidentAct($incident, 'note'));
    }

    public function test_approval_keeps_other_incident_discrepancy_and_financial_holds_without_changing_money(): void
    {
        $order = $this->stocked();
        $order->update(['financial_hold' => true]);
        $discrepancy = Discrepancy::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'warehouse_item_id' => $order->parcels()->firstOrFail()->id,
            'created_by' => $this->clerk->id, 'type' => 'damaged', 'severity' => 'high', 'reason' => 'Hàng móp cần xác minh riêng', 'blocks_dispatch' => true]);
        $first = $this->incident($order);
        $second = $this->incident($order);
        $this->conclude($first, 'fee_reduction', '20000');
        $this->assertFalse($first->blocks_dispatch);
        $this->assertTrue($first->blocks_reconciliation);
        $this->assertTrue($second->fresh()->blocks_dispatch);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        $this->conclude($second);
        $this->invalid(fn () => $this->assign($order, 'delivery'));
        $this->assertSame('open', $discrepancy->fresh()->status);
        $this->assertTrue($order->fresh()->financial_hold);
        $this->assertTrue($order->fresh()->reconciliationBlocked());
        $this->assertSame('500000.00', $order->fresh()->cod_amount);
        $this->assertSame('30000.00', $order->fresh()->shipping_fee);
        $this->assertSame(0, $order->collections()->count());
    }

    public function test_dispatch_and_pickup_honor_a_new_hold_but_return_to_warehouse_is_allowed(): void
    {
        $order = $this->order();
        $incident = $this->incident($order);
        $this->invalid(fn () => $this->assign($order));
        $this->conclude($incident);
        $task = $this->assign($order);
        $incident = $this->incident($order);
        $pickup = fn () => app(DispatchService::class)->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Nhận đủ hàng kiểm thử'], 'proof.png');
        $this->invalid($pickup);
        $this->assertSame(0, $order->parcels()->count());
        $this->conclude($incident);
        $pickup();
        $this->incident($order);
        $this->inbound($task->fresh());
        $this->assertSame('warehouse', $order->fresh()->custody_status);
    }

    public function test_hold_blocks_both_preparing_and_confirming_an_outbound_document(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $service = app(WarehouseService::class);
        $doc = $service->prepare($this->clerk, $task, 'outbound', 'Chuẩn bị xuất trước sự cố');
        $incident = $this->incident($order);
        $this->invalid(fn () => $service->confirm($this->shipper, $doc, ['reason' => 'Nhận hàng sau khi có khóa', 'lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()], null));
        $this->assertSame('pending', $doc->fresh()->status);
        $this->assertSame('warehouse', $order->fresh()->custody_status);
        $this->conclude($incident);
        app(DispatchService::class)->recall($this->dispatcher, $task, 'Hủy phiếu để phân công lại');
        $task = $this->assign($order, 'delivery');
        $this->incident($order);
        $this->invalid(fn () => $service->prepare($this->clerk, $task, 'outbound', 'Không được xuất khi có sự cố'));
    }

    public function test_hold_blocks_success_and_retry_but_not_failure_or_inbound(): void
    {
        $order = $this->stocked();
        $task = $this->assign($order, 'delivery');
        $stock = app(WarehouseService::class);
        $doc = $stock->prepare($this->clerk, $task, 'outbound', 'Xuất hàng kiểm thử');
        $stock->confirm($this->shipper, $doc, ['lines' => $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all(), 'reason' => 'Nhận đủ kiện kiểm thử'], null);
        $this->incident($order);
        $this->invalid(fn () => app(DeliveryService::class)->record($this->shipper, $task->fresh(), ['client_token' => (string) Str::uuid(), 'outcome' => 'success',
            'receiver_name' => 'Khách nhận', 'actual_cod' => 500000, 'actual_fee' => 30000, 'reason' => 'Cố xác nhận khi đang khóa'], $this->photo()));
        app(DeliveryService::class)->record($this->shipper, $task->fresh(), ['client_token' => (string) Str::uuid(), 'outcome' => 'failed', 'reason_code' => 'absent', 'reason' => 'Khách vắng và cần xác minh'], null);
        $this->assertSame(0, $order->collections()->count());
        $this->inbound($task->fresh());
        $this->invalid(fn () => app(DeliveryDecisionService::class)->decide($this->dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'decision' => 'retry',
            'shop_instruction' => 'Shop đề nghị giao tiếp', 'reason' => 'Chưa xử lý xong sự cố']));
    }

    public function test_complaint_creation_is_idempotent_sla_is_server_set_and_shop_cannot_use_another_order(): void
    {
        $order = $this->order();
        $data = ['client_token' => (string) Str::uuid(), 'title' => 'Shop cần hỗ trợ giao hàng', 'description' => 'Vui lòng kiểm tra và phản hồi tình trạng đơn.', 'type' => 'delay', 'due_at' => '2099-01-01', 'status' => 'closed'];
        $this->actingAs($this->owner)->post(route('complaints.store', $order), $data)->assertSessionHasNoErrors();
        $complaint = $order->complaints()->sole();
        $this->assertSame('open', $complaint->status);
        $this->assertEqualsWithDelta(48, now()->diffInHours($complaint->due_at), 0.01);
        $this->post(route('complaints.store', $order), $data)->assertRedirect(route('complaints.show', $complaint));
        $this->post(route('complaints.store', $order), array_replace($data, ['title' => 'Nội dung mới với mã cũ']))->assertSessionHasErrors('client_token');
        $this->assertSame(1, $complaint->messages()->count());
        $this->actingAs($this->shopOwner())->post(route('complaints.store', $order), $data)->assertForbidden();
    }

    public function test_internal_messages_and_attachments_are_hidden_from_shop_in_html_and_downloads(): void
    {
        $complaint = $this->complaint($this->order());
        $this->complaintAct($complaint, 'message', ['is_internal' => true, 'body' => 'BÍ MẬT NỘI BỘ KHÔNG GỬI SHOP'], file: $this->photo());
        $attachment = Attachment::sole();
        $this->assertNull($complaint->first_response_at);
        $this->actingAs($this->owner)->get(route('complaints.show', $complaint))->assertOk()->assertDontSee('BÍ MẬT NỘI BỘ KHÔNG GỬI SHOP')->assertDontSee(route('cases.attachment', $attachment));
        $this->get(route('cases.attachment', $attachment))->assertForbidden();
        $this->post(route('complaints.action', [$complaint, 'message']), ['revision' => $complaint->revision, 'is_internal' => 1, 'body' => 'Shop không được thêm nội bộ'])->assertForbidden();
        $this->actingAs($this->support)->get(route('cases.attachment', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->complaintAct($complaint, 'message', ['is_internal' => false, 'body' => 'Đã tiếp nhận và đang kiểm tra cùng kho.'], file: $this->photo());
        $this->assertNotNull($complaint->first_response_at);
        $public = $complaint->messages()->where('is_internal', false)->whereHas('attachments')->sole()->attachments->sole();
        $this->actingAs($this->owner)->get(route('cases.attachment', $public))->assertOk();
        $this->assertArrayNotHasKey('path', $public->toArray());
        $this->actingAs($this->shopOwner())->get(route('cases.attachment', $public))->assertForbidden();
    }

    public function test_assignment_reason_and_uploaded_evidence_remain_internal(): void
    {
        $complaint = $this->complaint($this->order());
        $this->complaintAct($complaint, 'assign', ['assigned_to' => $this->support->id, 'reason' => 'CĂN CỨ PHÂN CÔNG RIÊNG'], file: $this->photo());
        $this->assertSame('in_progress', $complaint->status);
        $this->assertTrue(Attachment::sole()->complaintMessage->is_internal);
        $this->actingAs($this->owner)->get(route('complaints.show', $complaint))->assertOk()->assertDontSee('CĂN CỨ PHÂN CÔNG RIÊNG');
        $this->invalid(fn () => $this->complaintAct($complaint, 'assign', ['assigned_to' => $this->clerk->id, 'reason' => 'Kho không có quyền tiếp nhận CSKH']), 'assigned_to');
    }

    public function test_goods_complaint_requires_same_order_approved_incident_before_closing(): void
    {
        $order = $this->order();
        $complaint = $this->complaint($order, ['type' => 'damaged']);
        $resolution = ['resolution' => 'Đã xác minh với shop và hoàn tất phản hồi.'];
        $this->invalid(fn () => $this->complaintAct($complaint, 'close', $resolution), 'resolution');
        $other = $this->incident($this->order());
        $this->invalid(fn () => $this->complaintAct($complaint, 'link', ['incident_id' => $other->id, 'reason' => 'Liên kết sai đơn phải bị chặn']), 'incident_id');
        $incident = $this->incident($order);
        $this->complaintAct($complaint, 'link', ['incident_id' => $incident->id, 'reason' => 'Cùng hàng cần được xác minh']);
        $this->invalid(fn () => $this->complaintAct($complaint, 'close', $resolution), 'resolution');
        $this->conclude($incident, 'compensation', '100000');
        $this->complaintAct($complaint, 'close', $resolution);
        $this->assertSame('closed', $complaint->status);
        $this->assertStringContainsString('chưa xác nhận chi tiền', $complaint->resolution);
        $this->assertTrue($order->fresh()->reconciliationBlocked());
        $this->actingAs($this->owner)->get(route('complaints.show', $complaint))->assertOk()->assertSee('chưa xác nhận chi tiền')->assertDontSee($incident->title);
    }

    public function test_overdue_reopen_keeps_original_deadline_and_rejects_reopen_after_seven_days(): void
    {
        $complaint = $this->complaint($this->order());
        $due = $complaint->due_at->toDateTimeString();
        $this->travel(49)->hours();
        $this->assertTrue($complaint->fresh()->isOverdue());
        $this->assertSame(1, Complaint::overdue()->count());
        $this->actingAs($this->support)->get(route('complaints.index', ['overdue' => 1]))->assertOk()->assertSee($complaint->code);
        $this->complaintAct($complaint, 'close', ['resolution' => 'Đã báo lại lịch giao được shop xác nhận.']);
        $this->assertFalse($complaint->isOverdue());
        $this->complaintAct($complaint, 'reopen', ['reason' => 'Shop cần xác minh thêm thông tin đơn'], $this->owner);
        $this->assertSame($due, $complaint->due_at->toDateTimeString());
        $this->assertTrue($complaint->isOverdue());
        $this->complaintAct($complaint, 'close', ['resolution' => 'Đã xử lý bổ sung và trao đổi đầy đủ.']);
        $this->travel(8)->days();
        $this->invalid(fn () => $this->complaintAct($complaint, 'reopen', ['reason' => 'Shop gửi lại sau thời hạn'], $this->owner));
        $this->travelBack();
    }

    public function test_stale_revisions_closed_cases_and_cross_role_actions_do_not_write_messages(): void
    {
        $complaint = $this->complaint($this->order());
        $this->complaintAct($complaint, 'message', ['is_internal' => false, 'body' => 'Đã tiếp nhận thông tin của shop.']);
        $count = $complaint->messages()->count();
        $this->invalid(fn () => $this->complaintAct($complaint, 'message', ['revision' => 1, 'is_internal' => false, 'body' => 'Gửi với phiên bản trang cũ']), 'revision');
        $this->assertSame($count, $complaint->messages()->count());
        $this->actingAs($this->owner)->post(route('complaints.action', [$complaint, 'close']), ['revision' => $complaint->revision, 'resolution' => 'Shop không thể tự đóng hồ sơ CSKH'])->assertForbidden();
        $this->complaintAct($complaint, 'close', ['resolution' => 'Đã xác minh và báo lại lịch giao hàng.']);
        $this->invalid(fn () => $this->complaintAct($complaint, 'message', ['is_internal' => false, 'body' => 'Không trao đổi khi hồ sơ đã đóng']));
    }

    public function test_case_evidence_validation_rejects_svg_and_oversize_uploads(): void
    {
        $order = $this->order();
        $this->actingAs($this->support)->post(route('incidents.store', $order), $this->incidentData() + ['evidence' => UploadedFile::fake()->createWithContent('proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertSessionHasErrors('evidence');
        $this->post(route('incidents.store', $order), $this->incidentData() + ['evidence' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('evidence');
        $this->assertSame(0, Attachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_pdf_is_downloaded_and_incident_evidence_is_never_available_to_shop(): void
    {
        $incident = app(IncidentService::class)->create($this->support, $this->order(), $this->incidentData(), UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\nEvidence\n%%EOF"));
        $attachment = $incident->actions()->sole()->attachments()->sole();
        $this->actingAs($this->support)->get(route('cases.attachment', $attachment))->assertOk()->assertDownload('bang-chung-'.$attachment->id.'.pdf');
        $this->actingAs($this->owner)->get(route('cases.attachment', $attachment))->assertForbidden();
        $this->support->warehouses()->detach();
        $this->actingAs($this->support)->get(route('cases.attachment', $attachment))->assertForbidden();
    }

    public function test_audit_failure_rolls_back_case_history_and_new_private_file(): void
    {
        $order = $this->order();
        $this->mock(AuditService::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        });
        try {
            app(IncidentService::class)->create($this->support, $order, $this->incidentData(), $this->photo());
            $this->fail('Phải rollback khi không thể ghi audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(0, $order->incidents()->count());
        $this->assertSame(0, $order->histories()->count());
        $this->assertSame(0, Attachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame(1, $order->fresh()->revision);
    }

    public function test_histories_and_evidence_are_append_only_and_cases_cannot_be_deleted(): void
    {
        $order = $this->order();
        $incident = app(IncidentService::class)->create($this->support, $order, $this->incidentData(), $this->photo());
        $complaint = $this->complaint($order);
        foreach ([$incident->actions()->sole(), $complaint->messages()->sole(), Attachment::sole()] as $model) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $operation === 'update' ? $model->update(['created_by' => $this->admin->id]) : $model->delete();
                    $this->fail('Không được sửa/xóa lịch sử hoặc bằng chứng.');
                } catch (\LogicException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                }
            }
        }
        foreach ([$incident, $complaint] as $case) {
            try {
                $case->delete();
                $this->fail('Không được xóa hồ sơ.');
            } catch (\LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_picker_filters_and_case_forms_render_for_the_correct_roles(): void
    {
        $order = $this->order();
        $incident = $this->incident($order);
        $complaint = $this->complaint($order);
        $this->actingAs($this->support)->get(route('incidents.create'))->assertOk();
        $this->get(route('incidents.create', ['order_id' => $order->id]))->assertOk()->assertSee($order->tracking_code);
        $this->get(route('incidents.show', $incident))->assertOk();
        $this->get(route('incidents.index', ['q' => $incident->code, 'status' => 'open']))->assertOk()->assertSee($incident->code);
        $this->get(route('complaints.show', $complaint))->assertOk();
        $this->actingAs($this->owner)->get(route('complaints.create'))->assertOk();
        $this->get(route('complaints.create', ['order_id' => $order->id]))->assertOk();
        $this->get(route('complaints.index', ['q' => $complaint->code]))->assertOk()->assertSee($complaint->code);
        $this->get(route('complaints.index', ['q' => 'KHONG-TON-TAI']))->assertOk()->assertDontSee($complaint->code);
    }
}
