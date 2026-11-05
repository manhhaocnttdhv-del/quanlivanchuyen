<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\CashDeposit;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCollection;
use App\Models\DeliveryTask;
use App\Models\FeeReceipt;
use App\Models\FinanceAttachment;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AdjustmentService;
use App\Services\AuditService;
use App\Services\CashDepositService;
use App\Services\FeeReceiptService;
use App\Services\FinanceLedger;
use App\Services\IncidentService;
use App\Services\PaymentService;
use App\Services\ReceivableService;
use App\Services\ReconciliationService;
use App\Services\ShopService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\DatabaseTestCase;

class FinanceWorkflowTest extends DatabaseTestCase
{
    private User $admin;

    private User $accountant;

    private User $owner;

    private User $shipper;

    private User $support;

    private Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->person();
        $this->accountant = $this->person('admin');
        $this->shipper = $this->person('shipper');
        $this->support = $this->person('operations');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        foreach ([$this->accountant, $this->shipper, $this->support] as $user) {
            $user->warehouses()->attach($this->depot);
        }
        app(ShopService::class)->replaceBank($this->owner, $this->owner->shop, ['bank_name' => 'Ngân hàng test', 'holder_name' => 'SHOP TEST', 'account_number' => '1234567890']);
        app(ShopService::class)->verifyBank($this->admin, $this->owner->shop->fresh()->currentBankAccount, 'Đã xác minh ngân hàng kiểm thử');
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function collection(int $amount = 500000, int $expected = 500000, string $type = 'cod', string $payer = 'sender'): DeliveryCollection
    {
        $order = Order::create(['shop_id' => $this->owner->shop->id, 'created_by' => $this->owner->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
            'status' => 'delivered', 'submitted_at' => now(), 'delivered_at' => now(), 'area_id' => $this->depot->area_id, 'origin_area_id' => $this->depot->area_id, 'warehouse_id' => $this->depot->id, 'parcel_count' => 1, 'weight_grams' => 500,
            'cod_amount' => 500000, 'shipping_fee' => 30000, 'fee_payer' => $payer, 'financial_hold' => $amount !== $expected]);
        $task = DeliveryTask::create(['order_id' => $order->id, 'shipper_id' => $this->shipper->id, 'warehouse_id' => $this->depot->id, 'type' => 'delivery', 'created_by' => $this->admin->id, 'status' => 'completed', 'reason' => 'Nhiệm vụ nguồn cho kiểm thử tài chính']);
        $attempt = DeliveryAttempt::create(['order_id' => $order->id, 'delivery_task_id' => $task->id, 'shipper_id' => $this->shipper->id, 'type' => 'delivery', 'attempt_number' => 1, 'outcome' => 'success',
            'reason' => 'Dữ liệu nguồn cho test tài chính', 'client_token' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'attempted_at' => now()]);
        app(ReceivableService::class)->accrue($order);

        return DeliveryCollection::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'delivery_attempt_id' => $attempt->id, 'shipper_id' => $this->shipper->id, 'type' => $type,
            'expected_amount' => $expected, 'amount' => $amount, 'financial_status' => $expected === $amount ? 'matched' : 'disputed', 'idempotency_key' => hash('sha256', (string) Str::uuid()), 'collected_at' => now()]);
    }

    private function depositData(array $amounts): array
    {
        return ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id, 'shipper_id' => $this->shipper->id, 'allocations' => $amounts, 'reason' => 'Shipper đề nghị nộp tiền thực thu'];
    }

    private function deposit(DeliveryCollection $c, ?int $amount = null): CashDeposit
    {
        return app(CashDepositService::class)->create($this->shipper, $this->depositData([$c->id => $amount ?? (int) $c->amount]));
    }

    private function confirm(CashDeposit $deposit, array $amounts, ?int $received = null): void
    {
        app(CashDepositService::class)->confirm($this->accountant, $deposit, ['revision' => $deposit->fresh()->revision, 'received_amount' => $received ?? array_sum($amounts), 'allocations' => $amounts, 'transaction_reference' => 'NT-'.Str::uuid(), 'reason' => 'Kế toán đã kiểm đếm tiền thực nhận'], $this->photo());
    }

    private function funded(int $amount = 500000): DeliveryCollection
    {
        $c = $this->collection($amount, $amount);
        $this->confirm($this->deposit($c), [$c->id => $amount]);

        return $c;
    }

    private function reconciliationData(array $cod, array $fees = [], array $adjustments = []): array
    {
        return ['client_token' => (string) Str::uuid(), 'shop_id' => $this->owner->shop->id, 'warehouse_id' => $this->depot->id, 'period_start' => now()->subDay()->format('Y-m-d'), 'period_end' => now()->addDay()->format('Y-m-d'),
            'cod' => $cod, 'fees' => $fees, 'adjustment_ids' => $adjustments, 'reason' => 'Lập đối soát theo tiền đã về quỹ'];
    }

    private function recAct(Reconciliation $doc, string $operation, ?User $actor = null, array $extra = []): void
    {
        app(ReconciliationService::class)->act($actor ?? $this->accountant, $doc->fresh(), $operation, $extra + ['revision' => $doc->fresh()->revision, 'reason' => 'Đã kiểm tra nguồn và căn cứ đối soát']);
        $doc->refresh();
    }

    private function approve(Reconciliation $doc): void
    {
        $this->recAct($doc, 'submit');
        $this->recAct($doc, 'approve', $this->admin);
    }

    private function invalid(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('Phải từ chối thao tác không hợp lệ.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
            if ($field) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    private function incident(Order $order, string $outcome, int $amount): Incident
    {
        $service = app(IncidentService::class);
        $doc = $service->create($this->support, $order, ['client_token' => (string) Str::uuid(), 'type' => 'information', 'severity' => 'high', 'title' => 'Căn cứ xử lý tài chính', 'description' => 'Đã xác minh đầy đủ cùng shop và chứng từ', 'blocks_dispatch' => false, 'blocks_reconciliation' => true], $this->photo());
        $service->act($this->support, $doc, 'propose', ['revision' => 1, 'outcome' => $outcome, 'amount' => $amount, 'conclusion' => 'Đồng ý phương án theo chứng từ xác minh', 'reason' => 'Đã đối chiếu chứng từ thực tế']);
        $service->act($this->admin, $doc->fresh(), 'review', ['revision' => 2, 'decision' => 'approved', 'reason' => 'Người khác đã kiểm tra và duyệt kết luận']);

        return $doc->fresh();
    }

    private function adjustment(Incident $incident, string $kind, array $target = []): Adjustment
    {
        return app(AdjustmentService::class)->create($this->accountant, $incident->order, $target + ['client_token' => (string) Str::uuid(), 'incident_id' => $incident->id, 'kind' => $kind, 'amount' => (int) $incident->approvedAction->proposed_amount, 'reason' => 'Lập điều chỉnh khớp kết luận đã duyệt'], $this->photo());
    }

    private function adjustmentApprove(Adjustment $doc): void
    {
        app(AdjustmentService::class)->review($this->admin, $doc, 'approve', ['revision' => 1, 'reason' => 'Đã kiểm tra nguồn và chứng từ điều chỉnh']);
    }

    public function test_finance_form_recovers_after_nested_amount_and_token_validation_errors(): void
    {
        $source = $this->collection();
        $form = route('finance.deposits.create', ['warehouse_id' => $this->depot->id, 'shipper_id' => $this->shipper->id]);
        $data = $this->depositData([$source->id => ['invalid']]);
        $data['client_token'] = ['invalid'];
        $this->actingAs($this->shipper)->from($form)->post(route('finance.deposits.store'), $data)
            ->assertRedirect($form)->assertSessionHasErrors(['client_token', 'allocations.'.$source->id]);
        $this->get($form)->assertOk()->assertSee('id="allocations-'.$source->id.'-error"', false)
            ->assertSee('aria-invalid="true"', false);
        $this->assertSame(0, CashDeposit::count());
    }

    public function test_partial_and_excess_deposit_allocations_preserve_cod_fee_and_cash_identity(): void
    {
        $c = $this->collection();
        $fee = $this->collection(30000, 30000, 'shipping_fee', 'recipient');
        $doc = app(CashDepositService::class)->create($this->shipper, $this->depositData([$c->id => 500000, $fee->id => 30000]));
        $this->confirm($doc, [$c->id => 200000], 210000);
        $ledger = app(FinanceLedger::class);
        $this->assertSame(300000, $ledger->held($c));
        $this->assertSame(30000, $ledger->held($fee));
        $this->assertSame(10000, $ledger->unallocated($doc->fresh()));
        app(CashDepositService::class)->allocateRemaining($this->accountant, $doc->fresh(), ['revision' => 2, 'allocations' => [$fee->id => 10000], 'reason' => 'Phân bổ tiền thừa sau khi xác minh']);
        $this->assertSame(0, $ledger->unallocated($doc->fresh()));
        $this->assertSame(20000, $ledger->held($fee));
        $this->assertSame('500000.00', $c->fresh()->amount);
        $this->assertSame(2, DeliveryCollection::count());
        $this->invalid(fn () => app(CashDepositService::class)->allocateRemaining($this->accountant, $doc->fresh(), ['revision' => 3, 'allocations' => [$c->id => 1], 'reason' => 'Không được phân bổ vượt tiền nhận']), 'allocations');
    }

    public function test_deposit_replay_is_idempotent_and_confirmation_replay_does_not_add_money_or_files(): void
    {
        $c = $this->collection();
        $data = $this->depositData([$c->id => 500000]);
        $service = app(CashDepositService::class);
        $doc = $service->create($this->shipper, $data);
        $again = $service->create($this->shipper, $data);
        $this->assertSame($doc->id, $again->id);
        $confirmation = ['revision' => 1, 'allocations' => [$c->id => 500000], 'received_amount' => 500000, 'transaction_reference' => 'BANK-1234', 'reason' => 'Đã nhận tiền shipper đầy đủ'];
        $service->confirm($this->accountant, $doc, $confirmation, $this->photo());
        $service->confirm($this->accountant, $doc, $confirmation, $this->photo());
        $this->assertSame(1, $doc->allocations()->count());
        $this->assertSame(1, FinanceAttachment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->invalid(fn () => $service->confirm($this->accountant, $doc, array_replace($confirmation, ['received_amount' => 500001]), $this->photo()));
        $this->invalid(fn () => $service->create($this->shipper, array_replace($data, ['reason' => 'Nội dung khác với token gốc'])), 'client_token');
    }

    public function test_deposit_cannot_overallocate_other_shipper_or_self_confirm_even_with_finance_role(): void
    {
        $c = $this->collection();
        $doc = $this->deposit($c);
        $this->invalid(fn () => $this->confirm($doc, [$c->id => 500001]), 'allocations');
        $this->shipper->roles()->attach($this->accountant->roles()->first());
        $this->shipper->unsetRelation('roles');
        try {
            app(CashDepositService::class)->confirm($this->shipper, $doc, ['revision' => 1, 'allocations' => [], 'received_amount' => 0, 'transaction_reference' => 'SELF-1234', 'reason' => 'Tự xác nhận không được phép'], $this->photo());
            $this->fail('Không tự xác nhận');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $c->order->update(['warehouse_id' => $this->warehouse()->id]);
        $foreign = $this->person('shipper');
        $this->invalid(fn () => app(CashDepositService::class)->create($this->admin, array_replace($this->depositData([$c->id => 100]), ['shipper_id' => $foreign->id])), 'shipper_id');
    }

    public function test_reconciliation_only_reserves_funded_cod_and_prevents_double_selection(): void
    {
        $c = $this->collection();
        $this->confirm($this->deposit($c), [$c->id => 200000]);
        $service = app(ReconciliationService::class);
        $this->invalid(fn () => $service->create($this->accountant, $this->reconciliationData([$c->id => 200001])), 'cod');
        $doc = $service->create($this->accountant, $this->reconciliationData([$c->id => 150000]));
        $this->assertSame('150000.00', $doc->payable_amount);
        $this->invalid(fn () => $service->create($this->accountant, $this->reconciliationData([$c->id => 50001])), 'cod');
        $this->recAct($doc, 'cancel');
        $this->assertSame(200000, app(FinanceLedger::class)->codAvailable($c));
    }

    public function test_reconciliation_fees_separate_from_cod_and_partial_payments_failed_attempts_and_replay(): void
    {
        $c = $this->funded();
        $fee = Receivable::where('order_id', $c->order_id)->sole();
        $data = $this->reconciliationData([$c->id => 500000], [$fee->id => 30000]);
        $service = app(ReconciliationService::class);
        $doc = $service->create($this->accountant, $data);
        $this->assertSame($doc->id, $service->create($this->accountant, $data)->id);
        $this->approve($doc);
        $this->assertSame('470000.00', $doc->payable_amount);
        $this->assertSame(0, app(FinanceLedger::class)->feeAvailable($fee));
        $payment = ['client_token' => (string) Str::uuid(), 'amount' => 200000, 'transaction_reference' => 'PAY-1234', 'status' => 'failed', 'reason' => 'Ngân hàng báo chuyển tiền thất bại'];
        app(PaymentService::class)->record($this->accountant, $doc, $payment, $this->photo());
        $this->assertSame(0, app(FinanceLedger::class)->paid($doc->id));
        $payment['client_token'] = (string) Str::uuid();
        $payment['transaction_reference'] = 'PAY-5678';
        $payment['status'] = 'succeeded';
        $paid = app(PaymentService::class)->record($this->accountant, $doc, $payment, $this->photo());
        $this->assertSame($paid->id, app(PaymentService::class)->record($this->accountant, $doc, $payment, $this->photo())->id);
        $this->assertSame(200000, app(FinanceLedger::class)->paid($doc->id));
        $this->invalid(fn () => app(PaymentService::class)->record($this->accountant, $doc, array_replace($payment, ['client_token' => (string) Str::uuid(), 'transaction_reference' => 'PAY-9999', 'amount' => 270001]), $this->photo()), 'amount');
    }

    public function test_no_self_approval_stale_revision_and_bank_change_requires_return_and_replacement(): void
    {
        $c = $this->funded();
        $doc = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 500000]));
        $this->recAct($doc, 'submit');
        try {
            $this->recAct($doc, 'approve');
            $this->fail('Không tự duyệt');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->invalid(fn () => $this->recAct($doc, 'approve', $this->admin, ['revision' => 1]), 'revision');
        app(ShopService::class)->replaceBank($this->owner, $this->owner->shop, ['bank_name' => 'Ngân hàng mới', 'holder_name' => 'SHOP TEST', 'account_number' => '9876543210']);
        app(ShopService::class)->verifyBank($this->admin, $this->owner->shop->fresh()->currentBankAccount, 'Xác minh phiên bản ngân hàng mới');
        $this->invalid(fn () => $this->recAct($doc, 'approve', $this->admin));
        $this->recAct($doc, 'return', $this->admin);
        $this->recAct($doc, 'replace', extra: ['cod' => [$c->id => 500000], 'fees' => [], 'adjustment_ids' => []]);
        $this->assertSame(2, $doc->line_version);
        $this->assertSame(2, $doc->allLines()->count());
        $this->approve($doc);
        $this->assertSame('3210', $doc->bank_snapshot['last_four']);
        $this->assertStringNotContainsString('9876543210', DB::table('reconciliations')->where('id', $doc->id)->value('bank_snapshot'));
        $this->assertArrayNotHasKey('bank_snapshot', $doc->toArray());
    }

    public function test_negative_reconciliation_creates_separate_debt_without_double_fee_collection(): void
    {
        $c = $this->funded(10000);
        $fee = Receivable::where('order_id', $c->order_id)->sole();
        $doc = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 10000], [$fee->id => 30000]));
        $this->approve($doc);
        $this->assertSame('0.00', $doc->payable_amount);
        $this->assertSame('20000.00', $doc->debt_amount);
        $debt = Receivable::where('reconciliation_id', $doc->id)->sole();
        $data = ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id, 'shop_id' => $this->owner->shop->id, 'amount' => 10000, 'allocations' => [$debt->id => 10000], 'transaction_reference' => 'FEE-1234', 'reason' => 'Shop nộp một phần nợ phí đối soát'];
        $receipt = app(FeeReceiptService::class)->record($this->accountant, $data, $this->photo());
        $this->assertSame($receipt->id, app(FeeReceiptService::class)->record($this->accountant, $data, $this->photo())->id);
        $this->assertSame(10000, app(FinanceLedger::class)->feeAvailable($debt));
        $this->assertSame(0, app(FinanceLedger::class)->feeAvailable($fee));
        $this->invalid(fn () => app(FeeReceiptService::class)->record($this->accountant, array_replace($data, ['client_token' => (string) Str::uuid(), 'transaction_reference' => 'FEE-5678', 'allocations' => [$fee->id => 10000]]), $this->photo()), 'allocations');
    }

    public function test_financial_incident_adjustment_is_reviewed_and_does_not_erase_other_holds_or_collect_money(): void
    {
        $c = $this->collection(490000, 500000);
        $incident = $this->incident($c->order, 'financial_correction', 10000);
        $doc = $this->adjustment($incident, 'collection_increase', ['delivery_collection_id' => $c->id]);
        $this->assertSame(490000, app(FinanceLedger::class)->effective($c));
        $this->assertTrue($c->order->fresh()->reconciliationBlocked());
        $this->adjustmentApprove($doc);
        $this->assertSame(500000, app(FinanceLedger::class)->effective($c));
        $this->assertSame(0, app(FinanceLedger::class)->deposited($c->id));
        $this->assertSame('490000.00', $c->fresh()->amount);
        $this->assertFalse($c->order->fresh()->financial_hold);
        $this->assertFalse($incident->fresh()->blocks_reconciliation);
        $this->assertSame('processed', $incident->fresh()->financial_status);
    }

    public function test_verification_accepts_actual_short_collection_without_inventing_cash(): void
    {
        $c = $this->collection(490000, 500000);
        $incident = $this->incident($c->order, 'financial_correction', 10000);
        $doc = $this->adjustment($incident, 'collection_verification', ['delivery_collection_id' => $c->id]);
        $this->adjustmentApprove($doc);
        $this->assertSame(490000, app(FinanceLedger::class)->effective($c));
        $this->assertFalse($c->order->fresh()->financial_hold);
        $this->confirm($this->deposit($c), [$c->id => 490000]);
        $this->assertSame(490000, app(FinanceLedger::class)->codAvailable($c));
    }

    public function test_fee_reduction_and_compensation_require_exact_approved_basis_and_release_only_their_incident(): void
    {
        $c = $this->funded();
        $fee = Receivable::where('order_id', $c->order_id)->sole();
        $incident = $this->incident($c->order, 'fee_reduction', 10000);
        $other = $this->incident($c->order, 'compensation', 20000);
        $doc = $this->adjustment($incident, 'fee_reduction', ['receivable_id' => $fee->id]);
        $this->adjustmentApprove($doc);
        $this->assertSame(20000, app(FinanceLedger::class)->feeAvailable($fee));
        $this->assertTrue($other->fresh()->blocks_reconciliation);
        $comp = $this->adjustment($other, 'compensation');
        $this->adjustmentApprove($comp);
        $rec = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 500000], [$fee->id => 20000], [$comp->id]));
        $this->assertSame('500000.00', $rec->payable_amount);
        $this->invalid(fn () => app(AdjustmentService::class)->create($this->accountant, $c->order, ['client_token' => (string) Str::uuid(), 'kind' => 'reversal', 'reverses_adjustment_id' => $comp->id, 'amount' => 20000, 'reason' => 'Không được đảo bồi thường đã vào đối soát'], $this->photo()));
    }

    public function test_reversal_keeps_original_lines_and_reopens_financial_incident(): void
    {
        $c = $this->collection(490000, 500000);
        $incident = $this->incident($c->order, 'financial_correction', 10000);
        $doc = $this->adjustment($incident, 'collection_increase', ['delivery_collection_id' => $c->id]);
        $this->adjustmentApprove($doc);
        $reversal = app(AdjustmentService::class)->create($this->accountant, $c->order, ['client_token' => (string) Str::uuid(), 'kind' => 'reversal', 'reverses_adjustment_id' => $doc->id, 'amount' => 10000, 'reason' => 'Căn cứ mới yêu cầu đảo toàn bộ điều chỉnh'], $this->photo());
        $this->adjustmentApprove($reversal);
        $this->assertSame(490000, app(FinanceLedger::class)->effective($c));
        $this->assertTrue($incident->fresh()->blocks_reconciliation);
        $this->assertTrue($c->order->fresh()->financial_hold);
        $this->assertSame('10000.00', $doc->line->amount);
        $this->assertSame('-10000.00', $reversal->line->amount);
    }

    public function test_reconciliation_block_rechecked_on_approval_and_payment(): void
    {
        $c = $this->funded();
        $rec = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 500000]));
        $this->recAct($rec, 'submit');
        $c->order->update(['financial_hold' => true]);
        $this->invalid(fn () => $this->recAct($rec, 'approve', $this->admin));
        $c->order->update(['financial_hold' => false]);
        $this->recAct($rec, 'approve', $this->admin);
        $c->order->update(['financial_hold' => true]);
        $this->invalid(fn () => app(PaymentService::class)->record($this->accountant, $rec, ['client_token' => (string) Str::uuid(), 'amount' => 1, 'status' => 'succeeded', 'transaction_reference' => 'BLOCK-1234', 'reason' => 'Không trả tiền khi có tranh chấp mới'], $this->photo()));
    }

    public function test_financial_values_reject_floats_and_overflow_and_handle_decimal_max_exactly(): void
    {
        $c = $this->collection(9999999999999, 9999999999999);
        $doc = $this->deposit($c);
        $this->confirm($doc, [$c->id => 9999999999999]);
        $this->assertSame(9999999999999, app(FinanceLedger::class)->deposited($c->id));
        $this->assertSame(0, app(FinanceLedger::class)->held($c));
        $this->invalid(fn () => app(CashDepositService::class)->create($this->shipper, $this->depositData([$c->id => '1.25'])));
        $this->invalid(fn () => app(CashDepositService::class)->create($this->shipper, $this->depositData([$c->id => '10000000000000'])));
    }

    public function test_audit_failure_rolls_back_deposit_allocation_and_proof(): void
    {
        $c = $this->collection();
        $doc = $this->deposit($c);
        $this->mock(AuditService::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable')));
        try {
            $this->confirm($doc, [$c->id => 500000]);
            $this->fail('Phải rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('submitted', $doc->fresh()->status);
        $this->assertSame(0, $doc->allocations()->count());
        $this->assertSame(0, FinanceAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_posted_documents_and_lines_cannot_be_edited_or_deleted(): void
    {
        $c = $this->funded();
        $doc = CashDeposit::sole();
        foreach ([fn () => $doc->update(['received_amount' => 1]), fn () => $doc->delete(), fn () => $doc->allocations()->sole()->update(['amount' => 1]), fn () => $c->delete()] as $action) {
            try {
                $action();
                $this->fail('Không sửa/xóa tiền đã ghi sổ');
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_finance_http_pages_and_maker_checker_workflow_render_with_real_sources(): void
    {
        $c = $this->funded();
        $fee = Receivable::where('order_id', $c->order_id)->sole();
        $this->actingAs($this->accountant);
        foreach (['collections', 'deposits', 'reconciliations', 'fees', 'receipts', 'adjustments'] as $section) {
            $this->get(route('finance.index', $section))->assertOk();
        }
        foreach (['finance.deposits.create', 'finance.reconciliations.create', 'finance.receipts.create', 'finance.adjustments.create'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('finance.collections.show', $c))->assertOk()->assertSee('500.000');
        $this->get(route('finance.fees.show', $fee))->assertOk()->assertSee('30.000');
        $deposit = CashDeposit::sole();
        $this->get(route('finance.deposits.show', $deposit))->assertOk();
        $this->post(route('finance.reconciliations.store'), $this->reconciliationData([$c->id => 500000], [$fee->id => 30000]))->assertRedirect();
        $rec = Reconciliation::sole();
        $this->get(route('finance.reconciliations.edit', $rec))->assertOk();
        $this->actingAs($this->owner)->get(route('finance.reconciliations.show', $rec))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('finance.reconciliations.action', [$rec, 'submit']), ['revision' => 1, 'reason' => 'Gửi duyệt đối soát qua giao diện'])->assertRedirect();
        $this->post(route('finance.reconciliations.action', [$rec, 'approve']), ['revision' => 2, 'reason' => 'Không được tự duyệt đối soát'])->assertForbidden();
        $this->actingAs($this->owner)->get(route('finance.reconciliations.show', $rec))->assertOk();
        $this->actingAs($this->admin)->post(route('finance.reconciliations.action', [$rec, 'approve']), ['revision' => 2, 'reason' => 'Đã kiểm tra đối soát từ giao diện'])->assertRedirect();
        $this->actingAs($this->accountant)->get(route('finance.reconciliations.show', $rec))->assertOk()->assertSee('470.000')
            ->assertViewHas('document', fn ($document) => $document->lines->count() === 2 && $document->lines->first()->order_id === $c->order_id);
        $this->post(route('finance.payments.store', $rec), ['client_token' => (string) Str::uuid(), 'amount' => 100000, 'status' => 'succeeded', 'transaction_reference' => 'HTTP-PAY-1234', 'reason' => 'Thanh toán một phần qua giao diện', 'evidence' => $this->photo()])->assertRedirect();
        $this->assertSame(100000, app(FinanceLedger::class)->paid($rec->id));
        $this->get(route('finance.reconciliations.show', $rec))->assertOk()->assertSee('370.000');

        $other = $this->collection();
        $otherFee = Receivable::where('order_id', $other->order_id)->sole();
        $this->post(route('finance.receipts.store'), ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id, 'shop_id' => $this->owner->shop->id, 'allocations' => [$otherFee->id => 10000], 'amount' => 10000,
            'transaction_reference' => 'HTTP-FEE-1234', 'reason' => 'Thu một phần nợ phí qua giao diện', 'evidence' => $this->photo()])->assertRedirect();
        $receipt = FeeReceipt::sole();
        $this->get(route('finance.receipts.show', $receipt))->assertOk();
        $incident = $this->incident($other->order, 'fee_reduction', 5000);
        $this->get(route('finance.adjustments.create', ['order_id' => $other->order_id]))->assertOk()->assertSee($incident->code);
        $adjustment = $this->adjustment($incident, 'fee_reduction', ['receivable_id' => $otherFee->id]);
        $this->get(route('finance.adjustments.show', $adjustment))->assertOk();
    }

    public function test_finance_scope_and_evidence_do_not_leak_between_shops_or_warehouses(): void
    {
        $c = $this->funded();
        $deposit = CashDeposit::sole();
        $rec = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 500000]));
        $this->approve($rec);
        $outsider = $this->person('operations');
        $outsider->warehouses()->attach($this->depot);
        $otherOwner = $this->shopOwner();
        foreach ([$outsider, $otherOwner] as $user) {
            if ($user->hasRole('operations')) {
                $this->actingAs($user)->get(route('finance.index', 'deposits'))->assertForbidden();
                $this->get(route('finance.index', 'collections'))->assertForbidden();
            } else {
                $this->actingAs($user)->get(route('finance.index', 'deposits'))->assertOk()->assertDontSee($deposit->code);
                $this->get(route('finance.index', 'collections'))->assertOk()->assertDontSee($c->order->tracking_code);
            }
            $this->get(route('finance.deposits.show', $deposit))->assertForbidden();
            $this->get(route('finance.reconciliations.show', $rec))->assertForbidden();
            $this->get(route('finance.attachment', FinanceAttachment::sole()))->assertForbidden();
        }
        $this->actingAs($this->shipper)->get(route('finance.deposits.show', $deposit))->assertOk();
        $this->get(route('finance.attachment', FinanceAttachment::sole()))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->owner)->get(route('finance.deposits.show', $deposit))->assertForbidden();
        $this->get(route('finance.reconciliations.show', $rec))->assertOk();
        $this->post(route('finance.reconciliations.action', [$rec, 'reopen']), ['revision' => $rec->revision, 'reason' => 'Shop không có quyền mở duyệt lại'])->assertForbidden();
    }

    public function test_partial_payment_bank_change_requires_new_review_without_releasing_or_changing_money(): void
    {
        $c = $this->funded();
        $rec = app(ReconciliationService::class)->create($this->accountant, $this->reconciliationData([$c->id => 500000]));
        $this->approve($rec);
        $payment = ['client_token' => (string) Str::uuid(), 'amount' => 100000, 'status' => 'succeeded', 'transaction_reference' => 'BANK-OLD-1234', 'reason' => 'Thanh toán trước khi shop đổi ngân hàng'];
        app(PaymentService::class)->record($this->accountant, $rec, $payment, $this->photo());
        app(ShopService::class)->replaceBank($this->owner, $this->owner->shop->fresh(), ['bank_name' => 'Ngân hàng mới', 'holder_name' => 'SHOP TEST', 'account_number' => '9999991234']);
        $next = array_replace($payment, ['client_token' => (string) Str::uuid(), 'transaction_reference' => 'BANK-NEW-1234']);
        $this->invalid(fn () => app(PaymentService::class)->record($this->accountant, $rec, $next, $this->photo()));
        app(ShopService::class)->verifyBank($this->admin, $this->owner->shop->fresh()->currentBankAccount, 'Đã kiểm tra ngân hàng thay thế');
        $oldSnapshot = $rec->bank_snapshot;
        $this->recAct($rec, 'reopen', $this->admin);
        $this->assertSame(0, app(FinanceLedger::class)->codAvailable($c));
        $this->invalid(fn () => $this->recAct($rec, 'replace', $this->accountant, ['cod' => [$c->id => 1]]));
        $this->invalid(fn () => $this->recAct($rec, 'cancel'));
        $this->recAct($rec, 'refresh-bank');
        $this->assertNotSame($oldSnapshot, $rec->bank_snapshot);
        $this->recAct($rec, 'submit');
        $this->recAct($rec, 'approve', $this->admin);
        app(PaymentService::class)->record($this->accountant, $rec, $next, $this->photo());
        $this->assertSame('500000.00', $rec->payable_amount);
        $this->assertSame(200000, app(FinanceLedger::class)->paid($rec->id));
        $this->assertSame(1, $rec->line_version);
    }

    public function test_reversed_basis_can_be_corrected_again_without_modifying_original_document(): void
    {
        $c = $this->collection(490000, 500000);
        $incident = $this->incident($c->order, 'financial_correction', 10000);
        $first = $this->adjustment($incident, 'collection_increase', ['delivery_collection_id' => $c->id]);
        $this->adjustmentApprove($first);
        $reversal = app(AdjustmentService::class)->create($this->accountant, $c->order, ['client_token' => (string) Str::uuid(), 'kind' => 'reversal', 'reverses_adjustment_id' => $first->id, 'amount' => 10000, 'reason' => 'Đảo điều chỉnh theo căn cứ mới'], $this->photo());
        $this->adjustmentApprove($reversal);
        $next = $this->adjustment($incident->fresh(), 'collection_verification', ['delivery_collection_id' => $c->id]);
        $this->adjustmentApprove($next);
        $this->assertSame(490000, app(FinanceLedger::class)->effective($c));
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('10000.00', $first->fresh()->line->amount);
        $this->assertFalse($c->order->fresh()->reconciliationBlocked());
    }

    public function test_same_bank_reference_cannot_be_reused_in_another_finance_register(): void
    {
        $c = $this->funded();
        $reference = CashDeposit::sole()->transaction_reference;
        $fee = Receivable::where('order_id', $c->order_id)->sole();
        $this->invalid(fn () => app(FeeReceiptService::class)->record($this->accountant, ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id, 'shop_id' => $this->owner->shop->id, 'amount' => 1, 'allocations' => [$fee->id => 1], 'transaction_reference' => $reference, 'reason' => 'Không ghi cùng biên nhận hai lần'], $this->photo()), 'transaction_reference');
        $this->assertSame(30000, app(FinanceLedger::class)->feeAvailable($fee));
    }

    public function test_finance_dates_use_vietnam_midnight_and_totals_follow_the_same_filter(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 16:59:59', 'UTC'));
        $before = $this->collection();
        $this->travelTo(Carbon::parse('2026-10-28 17:00:00', 'UTC'));
        $start = $this->collection();
        $this->actingAs($this->accountant)->get(route('finance.index', ['section' => 'collections', 'from' => '2026-10-29', 'until' => '2026-10-29']))
            ->assertOk()->assertSee($start->order->tracking_code)->assertDontSee($before->order->tracking_code)
            ->assertViewHas('totals', fn ($totals) => (int) $totals->sole()->held === 500000);
        $this->get(route('finance.index', ['section' => 'collections', 'until' => '2026-10-28']))->assertOk()->assertSee($before->order->tracking_code)->assertDontSee($start->order->tracking_code);
        $this->confirm($this->deposit($start), [$start->id => 500000]);
        $data = $this->reconciliationData([$start->id => 500000]);
        $this->invalid(fn () => app(ReconciliationService::class)->create($this->accountant, array_replace($data, ['period_start' => '2026-10-28', 'period_end' => '2026-10-28'])), 'cod');
        $rec = app(ReconciliationService::class)->create($this->accountant, array_replace($data, ['period_start' => '2026-10-29', 'period_end' => '2026-10-29']));
        $this->assertSame('500000.00', $rec->cod_total);
    }
}
