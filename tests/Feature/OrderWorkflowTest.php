<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeeCalculator;
use App\Services\FeeVersionService;
use App\Services\OrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class OrderWorkflowTest extends DatabaseTestCase
{
    private User $owner;

    private User $admin;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(10, 0));
        $this->admin = $this->person();
        $this->owner = $this->shopOwner();
        $this->warehouse();
        $service = app(FeeVersionService::class);
        $version = $service->save($this->admin, $this->feeData());
        $service->publish($this->admin, $version, 'Biểu phí kiểm thử');
        $this->orders = app(OrderService::class);
    }

    private function details(array $overrides = []): array
    {
        return array_merge(['pickup_address_id' => $this->owner->shop->addresses()->value('id'),
            'area_id' => Area::where('code', 'HCM')->value('id'), 'recipient_name' => 'Người nhận kiểm thử',
            'recipient_phone' => '0987656789', 'recipient_address' => '20 Đường kiểm thử, phường mẫu',
            'weight_grams' => 501, 'parcel_count' => 2, 'service' => 'standard', 'cod_amount' => 500000,
            'fee_payer' => 'recipient', 'note' => 'Nội dung kiểm thử', 'items' => [['name' => 'Áo sơ mi', 'quantity' => 2]],
            'client_token' => (string) Str::uuid()], $overrides);
    }

    private function draft(array $overrides = []): Order
    {
        return $this->orders->saveDraft($this->owner, $this->details($overrides));
    }

    private function submitted(): Order
    {
        $order = $this->draft();
        $this->orders->submit($this->owner, $order, $order->revision);

        return $order->fresh();
    }

    private function approved(): Order
    {
        $order = $this->submitted();
        $this->orders->review($this->admin, $order, $order->revision, 'approved', 'Đủ thông tin tiếp nhận');

        return $order->fresh();
    }

    public function test_guest_is_redirected_and_unsupported_staff_cannot_manage_orders(): void
    {
        $this->get('/gui-hang')->assertRedirect('/dang-nhap');
        $this->get('/don-hang')->assertRedirect('/dang-nhap');
        $this->post('/don-hang', $this->details())->assertRedirect('/dang-nhap');
        $staff = $this->person('shipper');
        $this->actingAs($staff)->get('/don-hang')->assertForbidden();
        $this->actingAs($staff)->get('/gui-hang')->assertForbidden();
    }

    public function test_incomplete_draft_survives_new_session_and_keeps_valid_fields_only(): void
    {
        $data = ['client_token' => (string) Str::uuid(), 'service' => 'standard', 'fee_payer' => 'sender', 'cod_amount' => 0, 'parcel_count' => 1,
            'items' => [['name' => '', 'quantity' => '']], 'status' => 'delivered', 'shipping_fee' => 1];
        $this->actingAs($this->owner)->post('/don-hang', $data)->assertRedirect();
        $order = Order::where('shop_id', $this->owner->shop->id)->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->shipping_fee);
        $this->assertNull($order->recipient_name);
        $this->assertCount(0, $order->items);
        $this->flushSession();
        $this->actingAs($this->owner)->get(route('orders.show', $order))->assertOk()->assertSee('Chưa nhập');
        $this->postJson(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertUnprocessable()->assertJsonValidationErrors(['recipient_name', 'weight_grams', 'items']);
    }

    public function test_double_save_is_idempotent_and_token_reuse_with_different_payload_is_rejected(): void
    {
        $data = $this->details();
        $first = $this->orders->saveDraft($this->owner, $data);
        $second = $this->orders->saveDraft($this->owner, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::where('shop_id', $this->owner->shop->id)->count());
        $this->assertSame(1, $first->histories()->count());
        $this->actingAs($this->owner)->postJson(route('orders.store'), array_merge($data, ['cod_amount' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('client_token');
        $this->assertSame('500000.00', $first->fresh()->cod_amount);
    }

    public function test_unique_tracking_codes_and_database_idempotency_constraints(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $this->assertNotSame($first->tracking_code, $second->tracking_code);
        $this->assertStringStartsWith('GV', $first->tracking_code);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('orders')->where('id', $second->id)->update(['client_token' => $first->client_token]);
    }

    public function test_submit_rejects_pending_shop_and_foreign_or_inactive_pickup(): void
    {
        $order = $this->draft();
        $this->owner->shop->status = 'pending';
        $this->owner->shop->save();
        $this->actingAs($this->owner)->postJson(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $other = $this->shopOwner();
        $this->postJson(route('orders.store'), $this->details(['pickup_address_id' => $other->shop->addresses()->value('id')]))
            ->assertUnprocessable()->assertJsonValidationErrors('pickup_address_id');
        $this->owner->shop->addresses()->update(['is_active' => false]);
        $this->owner->shop->status = 'approved';
        $this->owner->shop->save();
        $this->postJson(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertUnprocessable()->assertJsonValidationErrors('pickup_address_id');
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_inactive_destination_and_missing_receiving_warehouse_reject_submission(): void
    {
        $order = $this->draft();
        Area::whereKey($order->area_id)->update(['is_active' => false]);
        $this->actingAs($this->owner)->postJson(route('orders.submit', $order), ['revision' => $order->revision])->assertUnprocessable()->assertJsonValidationErrors('area_id');
        Area::whereKey($order->area_id)->update(['is_active' => true]);
        DB::table('warehouses')->update(['is_active' => false]);
        $this->postJson(route('orders.submit', $order), ['revision' => $order->revision])->assertUnprocessable()->assertJsonValidationErrors('pickup_address_id');
        $this->assertSame(1, $order->histories()->count());
    }

    public function test_server_recomputes_fees_and_snapshots_are_preserved_when_catalog_changes(): void
    {
        $this->actingAs($this->owner)->post('/don-hang', $this->details(['shipping_fee' => 1, 'recipient_total' => 1]))->assertRedirect();
        $order = Order::where('shop_id', $this->owner->shop->id)->firstOrFail();
        $this->post(route('orders.submit', $order), ['revision' => $order->revision, 'shipping_fee' => 1])->assertRedirect();
        $order->refresh();
        $this->assertSame('45000.00', $order->shipping_fee);
        $this->assertSame('545000.00', $order->recipient_total);
        $this->assertSame('0.501', $order->weight_kg);
        $snapshot = $order->sender_snapshot;
        $this->owner->shop->addresses()->update(['address' => 'Địa chỉ đã đổi']);
        Area::whereKey($order->area_id)->update(['name' => 'Tên đã đổi']);
        $this->assertSame($snapshot, $order->fresh()->sender_snapshot);
        $this->assertNotSame('Tên đã đổi', $order->fresh()->recipient_snapshot['area']);
        $this->assertSame(45000, $order->fee_snapshot['total']);
    }

    public function test_fee_boundaries_payer_cod_and_effective_dates(): void
    {
        $origin = Area::where('code', 'HN')->firstOrFail();
        $destination = Area::where('code', 'HCM')->firstOrFail();
        $calculator = app(FeeCalculator::class);
        foreach ([1 => 38000, 500 => 38000, 501 => 45000, 1000 => 45000, 1001 => 52000, 20000 => 311000] as $grams => $fee) {
            $this->assertSame($fee, $calculator->calculate($origin, $destination, $grams, 'standard')['total']);
        }
        $sender = $calculator->calculate($origin, $destination, 501, 'express', 20000000, 'sender');
        $this->assertSame(57000, $sender['total']);
        $this->assertSame(20000000, $sender['recipient_total']);
        $this->assertSame(20057000, $calculator->calculate($origin, $destination, 501, 'express', 20000000, 'recipient')['recipient_total']);
        $this->travelTo(now()->setDate(2028, 1, 1)->startOfDay());
        $this->expectException(ValidationException::class);
        $calculator->calculate($origin, $destination, 500, 'standard');
    }

    public function test_cod_limit_and_weight_validation_cannot_be_bypassed(): void
    {
        $order = $this->draft(['cod_amount' => 20000001]);
        $this->actingAs($this->owner)->postJson(route('orders.submit', $order), ['revision' => $order->revision])
            ->assertUnprocessable()->assertJsonValidationErrors('cod_amount');
        foreach ([0, 20001, '500.5'] as $weight) {
            $this->postJson('/don-hang', $this->details(['weight_grams' => $weight]))->assertUnprocessable()->assertJsonValidationErrors('weight_grams');
        }
        $this->postJson('/don-hang', $this->details(['items' => [['name' => 'Hàng', 'quantity' => 1, 'price' => 1]]]))->assertUnprocessable()->assertJsonValidationErrors('items.0');
    }

    public function test_scoped_dispatcher_cannot_see_or_approve_other_warehouse_or_drafts(): void
    {
        $order = $this->submitted();
        $dispatcher = $this->person('dispatcher');
        $this->actingAs($dispatcher)->get(route('orders.show', $order))->assertForbidden();
        $this->postJson(route('orders.review', $order), ['revision' => $order->revision, 'decision' => 'approved', 'reason' => 'Đã kiểm tra hồ sơ'])->assertForbidden();
        $dispatcher->warehouses()->attach($order->warehouse_id);
        $this->get(route('orders.show', $order))->assertOk();
        $draft = $this->draft();
        $this->get(route('orders.show', $draft))->assertForbidden();
        $this->get(route('orders.index'))->assertOk()->assertViewHas('orders', fn ($list) => $list->total() === 1);
        $this->post(route('orders.review', $order), ['revision' => $order->revision, 'decision' => 'approved', 'reason' => 'Đã kiểm tra hồ sơ'])->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_needs_info_resubmit_approve_and_duplicate_transition(): void
    {
        $order = $this->submitted();
        $this->orders->review($this->admin, $order, $order->revision, 'needs_info', 'Bổ sung địa chỉ chi tiết');
        $order->refresh();
        $this->assertSame('needs_info', $order->status);
        $this->orders->saveDraft($this->owner, array_merge($this->details(), ['revision' => $order->revision]), $order);
        $order->refresh();
        $this->orders->submit($this->owner, $order, $order->revision);
        $order->refresh();
        $this->orders->review($this->admin, $order, $order->revision, 'approved', 'Thông tin đã đầy đủ');
        $before = $order->histories()->count();
        $this->actingAs($this->admin)->postJson(route('orders.review', $order), ['revision' => $order->fresh()->revision, 'decision' => 'approved', 'reason' => 'Lần duyệt bị lặp'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($before, $order->histories()->count());
        $this->assertSame($this->admin->id, $order->fresh()->approved_by);
    }

    public function test_stale_edit_and_submit_do_not_overwrite_newer_information(): void
    {
        $order = $this->draft();
        $revision = $order->revision;
        $this->orders->saveDraft($this->owner, array_merge($this->details(), ['revision' => $revision, 'cod_amount' => 700000]), $order);
        $this->actingAs($this->owner)->putJson(route('orders.update', $order), array_merge($this->details(), ['revision' => $revision]))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson(route('orders.submit', $order), ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('700000.00', $order->fresh()->cod_amount);
    }

    public function test_owner_cannot_access_edit_cancel_print_or_request_change_for_other_shop(): void
    {
        $order = $this->approved();
        $other = $this->shopOwner();
        $this->actingAs($other)->get(route('orders.show', $order))->assertForbidden();
        $this->get(route('orders.edit', $order))->assertForbidden();
        $this->get(route('orders.label', $order))->assertForbidden();
        $this->postJson(route('orders.request-cancel', $order), ['revision' => $order->revision, 'reason' => 'Hủy sai chủ sở hữu'])->assertForbidden();
        $this->get(route('orders.index', ['shop_id' => $order->shop_id]))->assertOk()->assertViewHas('orders', fn ($list) => $list->isEmpty());
    }

    public function test_approved_edit_goes_through_change_review_recalculation_and_reapproval(): void
    {
        $order = $this->approved();
        $this->actingAs($this->owner)->putJson(route('orders.update', $order), array_merge($this->details(), ['revision' => $order->revision]))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->post(route('orders.change', $order), array_merge($this->details(['weight_grams' => 1001, 'cod_amount' => 700000]), ['revision' => $order->revision, 'reason' => 'Khách bổ sung hàng hóa']))->assertRedirect();
        $this->assertSame('500000.00', $order->fresh()->cod_amount);
        $change = $order->changeRequests()->firstOrFail();
        $this->assertSame('pending', $change->status);
        $this->actingAs($this->admin)->post(route('orders.change-review', $change), ['decision' => 'approved', 'reason' => 'Đồng ý thông tin bổ sung'])->assertRedirect();
        $order->refresh();
        $this->assertSame('submitted', $order->status);
        $this->assertSame('700000.00', $order->cod_amount);
        $this->assertSame('52000.00', $order->shipping_fee);
        $this->assertSame('752000.00', $order->recipient_total);
        $this->assertNull($order->approved_at);
        $this->assertSame('approved', $change->fresh()->status);
        $this->orders->review($this->admin, $order, $order->revision, 'approved', 'Duyệt lại sau điều chỉnh');
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_pending_change_is_unique_rejection_keeps_original_data_and_no_self_approval(): void
    {
        $order = $this->approved();
        $this->orders->requestChange($this->owner, $order, $order->revision, 'cancel', [], 'Khách yêu cầu hủy');
        $order->refresh();
        $change = $order->changeRequests()->firstOrFail();
        $this->actingAs($this->owner)->postJson(route('orders.request-cancel', $order), ['revision' => $order->revision, 'reason' => 'Gửi lặp đề nghị hủy'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->owner->roles()->attach(Role::where('name', 'admin')->value('id'));
        $this->postJson(route('orders.change-review', $change), ['decision' => 'approved', 'reason' => 'Tự duyệt đề nghị hủy'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->orders->reviewChange($this->admin, $change, 'rejected', 'Khách xác nhận giữ đơn');
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame('500000.00', $order->fresh()->cod_amount);
        $this->assertSame('rejected', $change->fresh()->status);
    }

    public function test_cancellation_matrix_and_held_or_delivered_orders_are_protected(): void
    {
        $draft = $this->draft();
        $this->orders->cancel($this->owner, $draft, $draft->revision, 'Khách đổi ý đặt hàng');
        $this->assertSame('cancelled', $draft->fresh()->status);
        $order = $this->approved();
        $this->actingAs($this->owner)->postJson(route('orders.cancel', $order), ['revision' => $order->revision, 'reason' => 'Không được hủy trực tiếp'])->assertUnprocessable();
        $this->orders->requestChange($this->owner, $order, $order->revision, 'cancel', [], 'Xin hủy trước lấy hàng');
        $this->orders->reviewChange($this->admin, $order->changeRequests()->firstOrFail(), 'approved', 'Đồng ý hủy trước lấy');
        $this->assertSame('cancelled', $order->fresh()->status);
        foreach (['at_warehouse', 'delivered'] as $status) {
            $protected = $this->approved();
            $protected->update(['status' => $status, 'custody_status' => 'warehouse']);
            $this->postJson(route('orders.cancel', $protected), ['revision' => $protected->revision, 'reason' => 'Hủy khi đã giữ kiện'])->assertUnprocessable();
            $this->postJson(route('orders.request-cancel', $protected), ['revision' => $protected->revision, 'reason' => 'Đề nghị hủy kiện đang giữ'])->assertUnprocessable();
            $this->assertSame($status, $protected->fresh()->status);
        }
    }

    public function test_history_and_audit_rollback_atomically_and_history_is_append_only(): void
    {
        $order = $this->draft();
        $count = AuditLog::count();
        $audit = $this->mock(AuditService::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('Lỗi ghi audit'));
        try {
            app(OrderService::class)->submit($this->owner, $order, $order->revision);
            $this->fail('Transaction phải rollback');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Lỗi ghi audit', $exception->getMessage());
        }
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->shipping_fee);
        $this->assertSame(1, $order->histories()->count());
        $this->assertSame($count, AuditLog::count());
        $history = $order->histories()->firstOrFail();
        $this->expectException(\LogicException::class);
        $history->update(['reason' => 'Sửa lịch sử']);
    }

    public function test_public_tracking_verifies_phone_hides_pii_internal_reason_and_drafts(): void
    {
        $order = $this->approved();
        $wrong = $this->postJson('/tra-cuu', ['tracking_code' => $order->tracking_code, 'phone_suffix' => '0000'])->assertOk()->json();
        $unknown = $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertOk()->json();
        $this->assertSame($wrong, $unknown);
        $result = $this->postJson('/tra-cuu', ['tracking_code' => strtolower($order->tracking_code), 'phone_suffix' => '6789'])->assertOk()->assertJsonPath('found', true)->json();
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach ([$order->recipient_phone, $order->recipient_address, $order->recipient_name, $this->admin->name, 'Đủ thông tin tiếp nhận', '500000'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame(['status', 'time'], array_keys($result['events'][0]));
        $draft = $this->draft();
        $this->postJson('/tra-cuu', ['tracking_code' => $draft->tracking_code, 'phone_suffix' => '6789'])->assertOk()->assertJsonPath('found', false);
        $this->postJson('/tra-cuu', ['tracking_code' => ['bad'], 'phone_suffix' => '12'])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertOk();
        }
        $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertStatus(429);
    }

    public function test_filters_pagination_and_eager_loading_stay_in_scope(): void
    {
        $order = $this->submitted();
        for ($i = 0; $i < 16; $i++) {
            $this->draft();
        }
        $other = $this->shopOwner();
        $foreign = $this->orders->saveDraft($other, $this->details(['pickup_address_id' => $other->shop->addresses()->value('id')]));
        $shipper = $this->person('shipper');
        $order->update(['assigned_shipper_id' => $shipper->id]);
        $response = $this->actingAs($this->owner)->get(route('orders.index', ['status' => 'draft', 'from' => '2026-10-28', 'until' => '2026-10-28']));
        $response->assertOk()->assertViewHas('orders', fn ($list) => $list->total() === 16 && $list->count() === 15)->assertSee('status=draft');
        $response->assertDontSee($foreign->tracking_code);
        $list = $response->viewData('orders');
        $this->assertTrue($list[0]->relationLoaded('shop'));
        $this->get(route('orders.index', ['q' => $order->tracking_code, 'area_id' => $order->area_id, 'assigned_shipper_id' => $shipper->id]))->assertOk()->assertViewHas('orders', fn ($list) => $list->total() === 1);
        $this->getJson('/don-hang?q[]=bad')->assertUnprocessable();
    }

    public function test_label_barcode_and_manifest_totals_follow_permissions_and_snapshots(): void
    {
        $order = $this->submitted();
        $label = $this->actingAs($this->owner)->get(route('orders.label', $order))->assertOk()->assertSee('<svg', false)->assertSee($order->tracking_code);
        $this->assertMatchesRegularExpression('/Kiện\s+1\/2/', $label->getContent());
        $this->assertMatchesRegularExpression('/Kiện\s+2\/2/', $label->getContent());
        $this->get(route('orders.manifest'))->assertOk()->assertSee('2 kiện')->assertSee('545.000');
        $draft = $this->draft();
        $this->get(route('orders.label', $draft))->assertForbidden();
        $this->get(route('orders.manifest'))->assertDontSee($draft->tracking_code);
        $order = $this->approved();
        $this->orders->requestChange($this->owner, $order, $order->revision, 'cancel', [], 'Chờ xác nhận hủy');
        $this->get(route('orders.label', $order))->assertUnprocessable();
        $this->get(route('orders.manifest'))->assertDontSee($order->tracking_code);
    }

    public function test_pages_validation_and_public_quote_use_effective_database_tariffs(): void
    {
        $this->get('/')->assertOk()->assertSee('Biểu phí kiểm thử');
        $this->postJson('/uoc-tinh-cuoc', ['origin' => 'HN', 'destination' => 'HCM', 'weight' => 501, 'service' => 'standard', 'total' => 1])->assertOk()->assertJsonPath('total', 45000);
        $this->postJson('/uoc-tinh-cuoc', ['origin' => ['HN'], 'destination' => 'HCM', 'weight' => 501, 'service' => 'standard'])->assertUnprocessable();
        $order = $this->approved();
        $this->actingAs($this->owner)->get('/gui-hang')->assertOk();
        $this->get(route('orders.edit', $order))->assertOk();
        $this->get(route('orders.show', $order))->assertOk();
        $this->from('/gui-hang')->post('/don-hang', $this->details(['recipient_name' => ['bad']]))->assertRedirect('/gui-hang')->assertSessionHasErrors('recipient_name');
        $this->get('/gui-hang')->assertOk();
    }

    public function test_listing_many_orders_has_bounded_queries_and_manifest_has_a_limit(): void
    {
        $template = $this->submitted()->getRawOriginal();
        unset($template['id']);
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = array_merge($template, ['tracking_code' => 'GV-PERFORMANCE-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'client_token' => (string) Str::uuid()]);
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($this->owner)->get('/don-hang?status=submitted')->assertOk()
            ->assertViewHas('orders', fn ($list) => $list->total() === 601 && $list->count() === 15);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(35, $queries, 'Số query không tăng theo số vận đơn.');
        $this->getJson(route('orders.manifest'))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_date_filter_uses_vietnam_midnight_and_until_can_be_used_alone(): void
    {
        $included = $this->draft();
        $included->created_at = '2026-10-27 18:00:00';
        $included->save();
        $excluded = $this->draft();
        $excluded->created_at = '2026-10-27 16:59:59';
        $excluded->save();
        $this->actingAs($this->owner)->get('/don-hang?from=2026-10-28&until=2026-10-28')->assertOk()
            ->assertViewHas('orders', fn ($list) => $list->total() === 1 && $list->first()->id === $included->id);
        $this->get('/don-hang?until=2026-10-28')->assertOk()->assertViewHas('orders', fn ($list) => $list->total() === 2);
    }
}
