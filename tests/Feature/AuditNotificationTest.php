<?php

namespace Tests\Feature;

use App\Jobs\DeliverBusinessNotification;
use App\Models\AuditLog;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryTask;
use App\Models\Incident;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Reconciliation;
use App\Models\Role;
use App\Models\ShopBankAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditSearch;
use App\Services\AuditService;
use App\Services\DispatchService;
use App\Services\FeeVersionService;
use App\Services\NotificationService;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\DatabaseTestCase;

class AuditNotificationTest extends DatabaseTestCase
{
    private User $admin;

    private User $dispatcher;

    private User $owner;

    private User $shipper;

    private Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Asia/Ho_Chi_Minh'));
        Bus::fake([DeliverBusinessNotification::class]);
        $this->admin = $this->person();
        $this->dispatcher = $this->person('operations');
        $this->owner = $this->shopOwner();
        $this->shipper = $this->person('shipper');
        $this->depot = $this->warehouse();
        $this->dispatcher->warehouses()->attach($this->depot);
        $this->shipper->warehouses()->attach($this->depot);
    }

    private function order(array $extra = []): Order
    {
        return Order::create(array_replace([
            'shop_id' => $this->owner->shop->id, 'created_by' => $this->owner->id,
            'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(),
            'creation_hash' => str_repeat('a', 64), 'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(),
            'area_id' => $this->depot->area_id, 'origin_area_id' => $this->depot->area_id, 'warehouse_id' => $this->depot->id,
            'shipping_fee' => 30000, 'cod_amount' => 500000, 'fee_payer' => 'sender', 'weight_grams' => 500, 'parcel_count' => 1,
        ], $extra))->refresh();
    }

    private function record($subject, string $event, array $after = [], ?User $actor = null): AuditLog
    {
        app(AuditService::class)->record($actor ?? $this->owner, $subject, $event, [], $after, 'Căn cứ kiểm thử thông báo');

        return AuditLog::latest('id')->firstOrFail();
    }

    private function deliver(AuditLog $log): void
    {
        $dispatch = NotificationDispatch::where('audit_log_id', $log->id)->sole();
        (new DeliverBusinessNotification($dispatch->id))->handle(app(NotificationService::class));
    }

    public function test_audit_scrubs_nested_secrets_without_rounding_money_and_masks_free_text(): void
    {
        $log = $this->record($this->order(), 'order.updated', [
            'password' => 'top-secret', 'session' => 'secret-session',
            'changes' => ['cod_amount' => '9999999999999.00', 'account_number' => '123456789012',
                'items' => [['name' => 'Sách', 'quantity' => 2, 'token' => 'nested-token', 'bank_snapshot' => ['account_number' => '123456789012']]]],
            'title' => '<script>alert(1)</script> account_number=123456789012',
        ]);
        $serialized = json_encode($log->new_values);
        foreach (['top-secret', 'secret-session', 'nested-token', '123456789012', 'bank_snapshot'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertSame('9999999999999.00', $log->new_values['changes']['cod_amount']);
        $this->assertSame(2, $log->new_values['changes']['items'][0]['quantity']);
        $this->actingAs($this->admin)->get(route('audit.show', $log))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_only_admin_with_audit_permission_can_read_system_audit_even_if_permission_is_granted_to_staff(): void
    {
        $log = $this->record($this->order(), 'order.updated');
        Role::where('name', 'operations')->sole()->permissions()->attach(Permission::where('name', 'audit.view')->sole());
        foreach ([$this->dispatcher, $this->shipper, $this->owner] as $user) {
            $this->actingAs($user)->get(route('audit.index'))->assertForbidden();
            $this->get(route('audit.show', $log))->assertForbidden();
        }
        $this->actingAs($this->admin)->get(route('audit.index'))->assertOk()->assertSee('order.updated');
    }

    public function test_audit_filters_actor_event_subject_request_and_vietnam_day_boundaries(): void
    {
        $order = $this->order();
        $logs = [];
        foreach (['2026-10-29 23:59:59', '2026-10-30 00:00:00', '2026-10-30 23:59:59', '2026-10-31 00:00:00'] as $at) {
            $this->travelTo(Carbon::parse($at));
            $logs[] = $this->record($order, 'order.reviewed');
        }
        $ids = app(AuditSearch::class)->query(['from' => '2026-10-30', 'until' => '2026-10-30', 'actor_id' => $this->owner->id,
            'event' => 'order.reviewed', 'subject_type' => 'Order', 'subject_id' => $order->id])->pluck('id')->all();
        $this->assertSame([$logs[2]->id, $logs[1]->id], $ids);
        $this->assertSame(4, app(AuditSearch::class)->query(['q' => $order->tracking_code])->count());
        $this->assertSame(4, app(AuditSearch::class)->query(['request_id' => $logs[0]->request_id, 'event' => 'order.reviewed'])->count());
        $this->actingAs($this->admin)->get(route('audit.index', ['subject_type' => 'ArbitraryClass']))->assertSessionHasErrors('subject_type');
        $this->get(route('audit.index', ['until' => '2026-10-30']))->assertOk();
    }

    public function test_http_request_id_is_shared_by_related_audits_but_not_between_requests(): void
    {
        $version = app(FeeVersionService::class)->save($this->admin, $this->feeData());
        app(FeeVersionService::class)->publish($this->admin, $version, 'Công bố phí kiểm thử');
        $order = app(OrderService::class)->saveDraft($this->owner, [
            'client_token' => (string) Str::uuid(), 'pickup_address_id' => $this->owner->shop->addresses()->value('id'),
            'warehouse_id' => $this->depot->id,
            'area_id' => $this->depot->area_id, 'recipient_name' => 'Khách kiểm thử', 'recipient_phone' => '0987654321',
            'recipient_address' => 'Địa chỉ kiểm thử', 'weight_grams' => 500, 'parcel_count' => 1, 'service' => 'standard',
            'cod_amount' => 500000, 'fee_payer' => 'sender', 'items' => [['name' => 'Sách', 'quantity' => 1]],
        ]);
        $response = $this->actingAs($this->owner)->post(route('orders.submit', $order), ['revision' => $order->revision]);
        $response->assertRedirect();
        $id = $response->headers->get('X-Request-ID');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, AuditLog::where('event', 'order.submitted')->latest('id')->sole()->request_id);
        $next = $this->get(route('notifications.index'));
        $this->assertNotSame($id, $next->headers->get('X-Request-ID'));
    }

    public function test_rollback_removes_audit_outbox_and_never_dispatches_job(): void
    {
        $order = $this->order();
        try {
            DB::transaction(function () use ($order) {
                $this->record($order, 'order.submitted');
                Bus::assertNotDispatched(DeliverBusinessNotification::class);
                throw new RuntimeException('Rollback nghiệp vụ');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Rollback nghiệp vụ', $error->getMessage());
        }
        $this->assertDatabaseMissing('audit_logs', ['event' => 'order.submitted', 'subject_id' => $order->id]);
        $this->assertDatabaseCount('notification_dispatches', 0);
        Bus::assertNotDispatched(DeliverBusinessNotification::class);
    }

    public function test_dispatch_happens_after_commit_and_retry_delivers_once_to_the_right_warehouse(): void
    {
        $outsider = $this->person('operations');
        $outsider->warehouses()->attach($this->warehouse('HCM'));
        $order = $this->order();
        DB::transaction(function () use ($order) {
            $this->record($order, 'order.submitted');
            Bus::assertNotDispatched(DeliverBusinessNotification::class);
        });
        Bus::assertDispatched(DeliverBusinessNotification::class, fn ($job) => $job->connection === 'notifications');
        $log = AuditLog::where('event', 'order.submitted')->sole();
        $this->deliver($log);
        $this->deliver($log);
        $this->assertSame(1, $this->dispatcher->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, $this->shipper->notifications()->count());
    }

    public function test_real_assignment_notifies_assigned_shipper_and_link_rechecks_current_membership(): void
    {
        $order = $this->order();
        $task = app(DispatchService::class)->assign($this->dispatcher, $order, ['revision' => $order->revision,
            'type' => 'pickup', 'shipper_id' => $this->shipper->id, 'reason' => 'Phân công lấy hàng kiểm thử']);
        $log = AuditLog::where('event', 'logistics.task_assigned')->sole();
        $this->assertSame($task->id, $log->new_values['delivery_task_id']);
        $this->deliver($log);
        $notification = $this->shipper->notifications()->sole();
        $this->actingAs($this->shipper)->get(route('notifications.open', $notification))->assertRedirect(route('logistics.show', $task));
        $this->shipper->warehouses()->detach();
        $this->get(route('notifications.open', $notification))->assertRedirect(route('notifications.index'))->assertSessionHasErrors('notification');
    }

    public function test_revoked_scope_and_locked_accounts_do_not_receive_queued_events(): void
    {
        $order = $this->order();
        $log = $this->record($order, 'order.submitted');
        $this->dispatcher->warehouses()->detach();
        $this->admin->forceFill(['status' => 'locked'])->save();
        $this->deliver($log);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_delivery_notifies_shop_and_dispatchers_without_exposing_reason_or_money(): void
    {
        $order = $this->order();
        $task = DeliveryTask::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id,
            'shipper_id' => $this->shipper->id, 'created_by' => $this->dispatcher->id, 'type' => 'delivery', 'status' => 'failed', 'reason' => 'Kiểm thử']);
        foreach (['success', 'failed'] as $number => $outcome) {
            $otherTask = $number ? $task->replicate() : $task;
            if ($number) {
                $otherTask->save();
            }
            $attempt = DeliveryAttempt::create(['order_id' => $order->id, 'delivery_task_id' => $otherTask->id, 'shipper_id' => $this->shipper->id,
                'type' => 'delivery', 'outcome' => $outcome, 'attempt_number' => $number + 1, 'client_token' => (string) Str::uuid(),
                'request_hash' => str_repeat('a', 64), 'reason' => 'Thông tin nội bộ', 'attempted_at' => now()]);
            $log = $this->record($attempt, 'delivery.attempt_recorded', $attempt->toArray(), $this->shipper);
            if ($outcome === 'failed') {
                $this->deliver($log);
            }
        }
        $this->assertDatabaseCount('notification_dispatches', 1);
        $data = $this->owner->notifications()->sole()->data;
        $this->assertSame('delivery', $data['category']);
        $this->assertArrayNotHasKey('reason', $data);
        $this->assertArrayNotHasKey('amount', $data);
    }

    public function test_incident_notifications_are_private_to_current_case_staff(): void
    {
        $support = $this->person('operations');
        $support->warehouses()->attach($this->depot);
        $order = $this->order();
        $incident = Incident::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'created_by' => $this->shipper->id,
            'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'code' => 'SC'.Str::ulid(),
            'type' => 'damaged', 'severity' => 'high', 'title' => 'Thông tin nội bộ sự cố', 'description' => 'Bằng chứng nội bộ']);
        $this->deliver($this->record($incident, 'incident.created', $incident->toArray(), $this->shipper));
        $this->assertSame(1, $support->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertStringNotContainsString('Bằng chứng', json_encode($support->notifications()->sole()->data));
    }

    public function test_reconciliation_notifies_approvers_shop_and_creator_with_status_permissions(): void
    {
        $accountant = $this->person('admin');
        $accountant->warehouses()->attach($this->depot);
        $reviewer = $this->person('admin');
        $reviewer->warehouses()->attach($this->depot);
        $bank = ShopBankAccount::create(['shop_id' => $this->owner->shop->id, 'version' => 1,
            'bank_name' => 'Bank test', 'holder_name' => 'SHOP', 'account_number' => '1234567890', 'last_four' => '7890', 'created_by' => $this->owner->id]);
        $rec = Reconciliation::create(['code' => 'DS'.Str::ulid(), 'shop_id' => $this->owner->shop->id, 'warehouse_id' => $this->depot->id,
            'created_by' => $accountant->id, 'client_token' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'shop_bank_account_id' => $bank->id, 'bank_snapshot' => [], 'period_start' => '2026-10-30', 'period_end' => '2026-10-30', 'status' => 'submitted', 'reason' => 'Kiểm thử']);
        $this->deliver($this->record($rec, 'finance.reconciliation_submit', $rec->toArray(), $accountant));
        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, $accountant->notifications()->count());
        $this->assertSame(0, $this->dispatcher->notifications()->count());
        $rec->update(['status' => 'approved']);
        $this->deliver($this->record($rec, 'finance.reconciliation_approve', $rec->toArray(), $reviewer));
        $this->assertSame(1, $accountant->notifications()->count());
        $this->assertSame(2, $this->owner->notifications()->count());
        $this->assertSame(0, $this->dispatcher->notifications()->count());
        $this->assertArrayNotHasKey('bank_snapshot', $this->owner->notifications()->latest()->first()->data);
    }

    public function test_inbox_ownership_read_unread_counts_filters_and_bulk_read_are_consistent(): void
    {
        foreach (range(1, 22) as $i) {
            $this->deliver($this->record($this->order(), 'order.submitted'));
        }
        $notification = $this->dispatcher->notifications()->first();
        $this->actingAs($this->dispatcher)->get(route('notifications.count'))->assertOk()->assertJsonPath('unread', 22);
        $this->get(route('notifications.index', ['state' => 'unread', 'category' => 'order']))->assertOk()->assertViewHas('notifications', fn ($rows) => $rows->total() === 22 && $rows->count() === 20);
        $this->patch(route('notifications.update', $notification), ['read' => 1])->assertRedirect();
        $this->get(route('notifications.count'))->assertJsonPath('unread', 21);
        $this->patch(route('notifications.update', $notification), ['read' => 0])->assertRedirect();
        $this->get(route('notifications.count'))->assertJsonPath('unread', 22);
        $foreign = $this->admin->notifications()->first();
        $this->patch(route('notifications.update', $foreign), ['read' => 1])->assertForbidden();
        $this->get(route('notifications.open', $foreign))->assertForbidden();
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->get(route('notifications.count'))->assertJsonPath('unread', 0);
        $this->assertSame(22, $this->admin->unreadNotifications()->count());
        $this->patch(route('notifications.update', $notification), ['read' => 'bad'])->assertSessionHasErrors('read');
        $this->get(route('notifications.index', ['state' => 'bad']))->assertSessionHasErrors('state');
    }

    public function test_pending_dispatch_recovery_requeues_stale_items_but_not_delivered_or_recent_items(): void
    {
        $log = $this->record($this->order(), 'order.submitted');
        $dispatch = NotificationDispatch::where('audit_log_id', $log->id)->sole();
        $dispatch->update(['queued_at' => now()->subMinutes(11)]);
        Bus::fake([DeliverBusinessNotification::class]);
        $this->artisan('notifications:dispatch-pending')->assertSuccessful();
        $this->artisan('notifications:dispatch-pending')->assertSuccessful();
        Bus::assertDispatchedTimes(DeliverBusinessNotification::class, 1);
        $this->deliver($log);
        $this->assertFalse(app(NotificationService::class)->enqueue($dispatch->id));
    }

    public function test_unavailable_queue_preserves_committed_audit_and_recoverable_outbox(): void
    {
        $bus = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $log = $this->record($this->order(), 'order.submitted');
        $dispatch = NotificationDispatch::where('audit_log_id', $log->id)->sole();
        $this->assertNull($dispatch->queued_at);
        $this->assertNull($dispatch->delivered_at);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        Bus::swap($bus);
        Bus::fake([DeliverBusinessNotification::class]);
        $this->artisan('notifications:dispatch-pending')->assertSuccessful();
        Bus::assertDispatchedTimes(DeliverBusinessNotification::class, 1);
        $this->deliver($log);
        $this->assertSame(1, $this->dispatcher->notifications()->count());
    }

    public function test_outbox_write_failure_rolls_back_business_changes_and_success_audit(): void
    {
        $order = $this->order();
        $this->mock(NotificationService::class)->shouldReceive('stage')->once()->andThrow(new RuntimeException('Outbox write failed'));
        try {
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'submitted']);
                $this->record($order, 'order.submitted');
            });
            $this->fail('Expected rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('Outbox write failed', $error->getMessage());
        }
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['subject_id' => $order->id, 'event' => 'order.submitted']);
        $this->assertDatabaseCount('notification_dispatches', 0);
    }

    public function test_audit_and_notification_content_are_immutable(): void
    {
        $log = $this->record($this->order(), 'order.submitted');
        $this->deliver($log);
        foreach ([fn () => $log->update(['reason' => 'Sửa']), fn () => $log->delete(),
            fn () => $this->dispatcher->notifications()->first()->update(['data' => ['title' => 'Sửa']])] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected immutable document rejection');
            } catch (LogicException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
    }
}
