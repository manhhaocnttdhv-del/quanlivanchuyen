<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCollection;
use App\Models\DeliveryTask;
use App\Models\ExportLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseItem;
use App\Services\AuditService;
use App\Services\CashDepositService;
use App\Services\FeeReceiptService;
use App\Services\FinanceLedger;
use App\Services\PaymentService;
use App\Services\ReceivableService;
use App\Services\ReconciliationService;
use App\Services\ReportExportService;
use App\Services\ReportFileWriter;
use App\Services\ReportService;
use App\Services\ShopService;
use App\Support\Money;
use App\Support\ReportValue;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\DatabaseTestCase;

class ReportWorkflowTest extends DatabaseTestCase
{
    private User $admin;

    private User $accountant;

    private User $dispatcher;

    private User $owner;

    private User $shipper;

    private Warehouse $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Asia/Ho_Chi_Minh'));
        Storage::fake('local');
        $this->admin = $this->person();
        $this->accountant = $this->person('accountant');
        $this->dispatcher = $this->person('dispatcher');
        $this->shipper = $this->person('shipper');
        $this->owner = $this->shopOwner();
        $this->depot = $this->warehouse();
        foreach ([$this->accountant, $this->dispatcher, $this->shipper] as $user) {
            $user->warehouses()->attach($this->depot);
        }
        app(ShopService::class)->replaceBank($this->owner, $this->owner->shop, ['bank_name' => 'Ngân hàng test', 'holder_name' => 'SHOP TEST', 'account_number' => '1234567890']);
        app(ShopService::class)->verifyBank($this->admin, $this->owner->shop->fresh()->currentBankAccount, 'Xác minh tài khoản cho kiểm thử');
    }

    private function filters(array $extra = []): array
    {
        return array_replace(['from' => '2026-10-30', 'until' => '2026-10-30', 'metric' => 'created', 'ledger' => 'collections', 'group_by' => 'warehouse'], $extra);
    }

    private function order(array $extra = [], ?User $owner = null, ?Warehouse $warehouse = null): Order
    {
        $owner ??= $this->owner;
        $warehouse ??= $this->depot;

        return Order::create(array_replace(['shop_id' => $owner->shop->id, 'created_by' => $owner->id,
            'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
            'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(), 'created_at' => now(),
            'area_id' => $warehouse->area_id, 'origin_area_id' => $warehouse->area_id, 'warehouse_id' => $warehouse->id,
            'shipping_fee' => 30000, 'cod_amount' => 500000, 'fee_payer' => 'sender', 'weight_grams' => 500], $extra));
    }

    private function attempt(Order $order, string $outcome = 'success', ?string $time = null, ?User $shipper = null, string $type = 'delivery'): DeliveryAttempt
    {
        $shipper ??= $this->shipper;
        $task = DeliveryTask::create(['order_id' => $order->id, 'warehouse_id' => $order->warehouse_id, 'shipper_id' => $shipper->id,
            'created_by' => $this->admin->id, 'type' => $type, 'status' => 'completed', 'reason' => 'Nhiệm vụ nguồn kiểm thử báo cáo']);

        return DeliveryAttempt::create(['order_id' => $order->id, 'delivery_task_id' => $task->id, 'shipper_id' => $shipper->id,
            'type' => $type, 'outcome' => $outcome, 'attempt_number' => $order->deliveryAttempts()->where('type', $type)->count() + 1,
            'client_token' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'reason' => 'Kết quả nguồn kiểm thử', 'attempted_at' => $time ?? now()]);
    }

    private function collection(int $amount = 500000, string $type = 'cod'): DeliveryCollection
    {
        $order = $this->order(['status' => 'delivered', 'delivered_at' => now()]);
        $attempt = $this->attempt($order);
        app(ReceivableService::class)->accrue($order);

        return DeliveryCollection::create(['order_id' => $order->id, 'delivery_attempt_id' => $attempt->id, 'warehouse_id' => $order->warehouse_id,
            'shipper_id' => $this->shipper->id, 'type' => $type, 'expected_amount' => $amount, 'amount' => $amount,
            'financial_status' => 'matched', 'idempotency_key' => hash('sha256', (string) Str::uuid()), 'collected_at' => now()]);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5foAAAAASUVORK5CYII='));
    }

    private function fund(DeliveryCollection $collection, ?int $amount = null, ?int $received = null): void
    {
        $amount ??= (int) $collection->amount;
        $service = app(CashDepositService::class);
        $deposit = $service->create($this->shipper, ['client_token' => (string) Str::uuid(), 'warehouse_id' => $collection->warehouse_id,
            'shipper_id' => $this->shipper->id, 'allocations' => [$collection->id => $amount], 'reason' => 'Đề nghị nộp tiền nguồn báo cáo']);
        $service->confirm($this->accountant, $deposit, ['revision' => 1, 'received_amount' => $received ?? $amount,
            'allocations' => [$collection->id => $amount], 'transaction_reference' => 'NT-'.Str::uuid(), 'reason' => 'Xác nhận thực nhận theo chứng từ'], $this->photo());
    }

    private function reconciliation(DeliveryCollection $collection, bool $fees = false): Reconciliation
    {
        $this->fund($collection);
        $data = ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id, 'shop_id' => $this->owner->shop->id,
            'period_start' => '2026-10-30', 'period_end' => '2026-10-30', 'cod' => [$collection->id => (int) $collection->amount],
            'fees' => $fees ? [Receivable::where('order_id', $collection->order_id)->firstOrFail()->id => 30000] : [],
            'reason' => 'Đối soát kiểm thử báo cáo'];
        $service = app(ReconciliationService::class);
        $document = $service->create($this->accountant, $data);
        foreach (['submit' => $this->accountant, 'approve' => $this->admin] as $operation => $actor) {
            $service->act($actor, $document->fresh(), $operation, ['revision' => $document->fresh()->revision, 'reason' => 'Đã đối chiếu số tiền và căn cứ']);
        }

        return $document->fresh();
    }

    private function report(string $type = 'operations', array $filters = [], ?User $user = null): array
    {
        return app(ReportService::class)->build($user ?? $this->admin, $type, $this->filters($filters));
    }

    private function export(string $type = 'operations', string $format = 'xlsx', array $filters = [], ?User $user = null, ?string $token = null): ExportLog
    {
        return app(ReportExportService::class)->create($user ?? $this->admin, $type, $this->filters($filters), $format, $token ?? (string) Str::uuid());
    }

    public function test_created_and_delivery_periods_do_not_drop_orders_created_before_period(): void
    {
        $order = $this->order(['created_at' => now()->subDays(5), 'submitted_at' => now()->subDays(5), 'status' => 'delivered']);
        $this->attempt($order, 'failed', '2026-10-29 23:59:59');
        $this->attempt($order);
        $report = $this->report('operations', ['metric' => 'delivered']);
        $this->assertSame(0, $report['summary']['created']['value']);
        $this->assertSame(1, $report['summary']['delivered']['value']);
        $this->assertSame(0, $report['summary']['failed']['value']);
        $this->assertSame(100.0, $report['summary']['success_rate']['value']);
        $this->assertSame($order->id, $report['rows']->first()->order_id);
        $this->assertSame('Giao thành công', $report['detail_title']);
        $this->assertContains('Chi tiết: Giao thành công', $report['filter_labels']);
    }

    public function test_failure_rate_counts_attempts_and_uses_the_shipper_at_the_actual_event(): void
    {
        $other = $this->person('shipper');
        $order = $this->order(['assigned_shipper_id' => $other->id]);
        $this->attempt($order, 'failed');
        $this->attempt($order);
        $this->attempt($order, 'success', null, $other, 'return');
        $report = $this->report('operations', ['shipper_id' => $this->shipper->id, 'metric' => 'delivered', 'group_by' => 'shipper']);
        $this->assertSame(50.0, $report['summary']['success_rate']['value']);
        $this->assertSame(0, $report['summary']['returned']['value']);
        $this->assertSame($this->shipper->name, $report['rows']->first()->shipper);
        $otherReport = $this->report('operations', ['shipper_id' => $other->id]);
        $this->assertSame(0, $otherReport['summary']['delivered']['value']);
        $this->assertSame(1, $otherReport['summary']['returned']['value']);
    }

    public function test_report_dates_use_vietnam_midnight_with_exclusive_next_day(): void
    {
        foreach (['2026-10-29 23:59:59', '2026-10-30 00:00:00', '2026-10-30 23:59:59', '2026-10-31 00:00:00'] as $time) {
            $order = $this->order(['created_at' => $time]);
            $this->attempt($order, 'success', $time);
        }
        $report = $this->report('operations', ['metric' => 'delivered']);
        $this->assertSame(2, $report['summary']['created']['value']);
        $this->assertSame(2, $report['rows']->total());
        $this->assertSame(2, $report['groups']->first()['delivered']);
    }

    public function test_current_inventory_counts_orders_once_and_overdue_excludes_terminal_orders(): void
    {
        $order = $this->order(['created_at' => now()->subDays(10), 'approved_at' => now()->subHours(73)]);
        foreach ([1, 2] as $number) {
            WarehouseItem::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'parcel_number' => $number, 'holder_type' => 'warehouse']);
        }
        $this->order(['approved_at' => now()->subDays(5), 'status' => 'delivered']);
        $this->order(['approved_at' => now()->subHours(72)]);
        $report = $this->report('operations', ['from' => '2026-09-01', 'until' => '2026-09-02', 'metric' => 'inventory']);
        $this->assertSame(1, $report['rows']->total());
        $this->assertSame(1, $report['summary']['overdue']['value']);
        $this->assertStringContainsString('hiện tại', $report['period_note']);
    }

    public function test_same_named_shops_have_separate_group_identifiers(): void
    {
        $other = $this->shopOwner();
        $this->order();
        $this->order([], $other);
        $report = $this->report('operations', ['group_by' => 'shop']);
        $this->assertCount(2, $report['groups']);
        $this->assertSame(2, $report['groups']->sum('created'));
        $this->assertCount(2, $report['groups']->pluck('group_id')->unique());
    }

    public function test_warehouse_and_shop_scope_apply_to_rows_totals_options_and_exports(): void
    {
        $own = $this->order();
        $foreignOwner = $this->shopOwner();
        $foreignOwner->shop->update(['name' => 'FOREIGN PRIVATE SHOP']);
        $foreignWarehouse = $this->warehouse('HCM');
        $foreign = $this->order([], $foreignOwner, $foreignWarehouse);
        foreach ([$this->dispatcher, $this->owner] as $actor) {
            $this->actingAs($actor)->get(route('reports.index', 'operations'))->assertOk()->assertDontSee('FOREIGN PRIVATE SHOP')->assertDontSee($foreign->tracking_code);
            $report = $this->report('operations', [], $actor);
            $this->assertSame(1, $report['summary']['created']['value']);
            $this->assertSame($own->id, $report['rows']->first()->order_id);
            $this->actingAs($actor)->get(route('reports.index', ['type' => 'operations', 'warehouse_id' => $foreignWarehouse->id]))->assertForbidden();
            $export = $this->export('operations', 'xlsx', [], $actor);
            $this->assertSame('ready', $export->status);
            $this->assertSame(1, $export->row_count);
        }
        $this->actingAs($this->shipper)->get(route('reports.index', 'operations'))->assertForbidden();
        $this->actingAs($this->dispatcher)->get(route('reports.index', 'finance'))->assertForbidden();
    }

    public function test_finance_collection_report_matches_ledger_and_never_adds_shipping_fee_to_cod(): void
    {
        $cod = $this->collection();
        $this->collection(30000, 'shipping_fee');
        $this->fund($cod, 200000);
        $report = $this->report('finance');
        $this->assertSame(500000, $report['summary']['effective']['value']);
        $this->assertSame(300000, $report['summary']['held']['value']);
        $this->assertSame(200000, $report['summary']['deposited']['value']);
        $row = $report['rows']->firstWhere('id', $cod->id);
        $this->assertSame(app(FinanceLedger::class)->held($cod), (int) $row->held);
        $this->assertSame(app(FinanceLedger::class)->codAvailable($cod), (int) $row->available);
        $this->assertCount(2, $report['groups']);
    }

    public function test_shop_with_other_finance_permissions_cannot_bypass_revoked_staff_report_scope(): void
    {
        $own = $this->collection();
        $otherOwner = $this->shopOwner();
        $order = $this->order(['status' => 'delivered'], $otherOwner);
        $attempt = $this->attempt($order);
        DeliveryCollection::create(['order_id' => $order->id, 'warehouse_id' => $this->depot->id, 'delivery_attempt_id' => $attempt->id,
            'shipper_id' => $this->shipper->id, 'type' => 'cod', 'amount' => 900000, 'expected_amount' => 900000,
            'financial_status' => 'matched', 'idempotency_key' => hash('sha256', (string) Str::uuid()), 'collected_at' => now()]);
        $role = Role::where('name', 'accountant')->firstOrFail();
        $this->owner->roles()->attach($role);
        $this->owner->warehouses()->attach($this->depot);
        $role->permissions()->detach(Permission::where('name', 'reports.finance')->value('id'));
        $report = $this->report('finance', [], $this->owner->fresh());
        $this->assertSame(500000, $report['summary']['effective']['value']);
        $this->assertSame($own->id, $report['rows']->sole()->id);
    }

    public function test_failed_and_partial_payments_and_bank_reapproval_keep_payables_correct(): void
    {
        $doc = $this->reconciliation($this->collection(), true);
        foreach (['failed' => 50000, 'succeeded' => 100000] as $status => $amount) {
            app(PaymentService::class)->record($this->accountant, $doc, ['client_token' => (string) Str::uuid(), 'amount' => $amount,
                'status' => $status, 'transaction_reference' => 'TT-'.Str::uuid(), 'reason' => 'Kết quả giao dịch ngân hàng kiểm thử'], $this->photo());
        }
        $payments = $this->report('finance', ['ledger' => 'payments']);
        $this->assertSame(100000, $payments['summary']['paid']['value']);
        $this->assertSame(150000, $payments['summary']['attempted']['value']);
        $this->assertSame(2, $payments['rows']->total());
        app(ReconciliationService::class)->act($this->admin, $doc->fresh(), 'reopen', ['revision' => $doc->fresh()->revision, 'reason' => 'Mở duyệt lại ngân hàng nhận tiền']);
        foreach ([$this->accountant, $this->owner] as $actor) {
            $report = $this->report('finance', ['ledger' => 'reconciliations'], $actor);
            $this->assertSame(370000, $report['summary']['remaining']['value']);
            $this->assertSame(100000, $report['summary']['paid']['value']);
        }
    }

    public function test_net_reconciliation_debt_does_not_create_fee_revenue_twice(): void
    {
        $doc = $this->reconciliation($this->collection(10000), true);
        $debt = Receivable::where('reconciliation_id', $doc->id)->firstOrFail();
        app(FeeReceiptService::class)->record($this->accountant, ['client_token' => (string) Str::uuid(), 'warehouse_id' => $this->depot->id,
            'shop_id' => $this->owner->shop->id, 'amount' => 5000, 'allocations' => [$debt->id => 5000],
            'transaction_reference' => 'TP-'.Str::uuid(), 'reason' => 'Thu một phần nợ phí sau đối soát'], $this->photo());
        $report = $this->report('finance', ['ledger' => 'fees']);
        $this->assertSame(30000, $report['summary']['original']['value']);
        $this->assertSame(15000, $report['summary']['outstanding']['value']);
        $this->assertSame(5000, $report['summary']['received']['value']);
    }

    public function test_cash_deposit_report_keeps_excess_unallocated_and_private_from_shops(): void
    {
        $this->fund($this->collection(), 200000, 250000);
        $report = $this->report('finance', ['ledger' => 'deposits'], $this->accountant);
        $this->assertSame(250000, $report['summary']['received']['value']);
        $this->assertSame(200000, $report['summary']['allocated']['value']);
        $this->assertSame(50000, $report['summary']['unallocated']['value']);
        $this->actingAs($this->owner)->getJson(route('reports.index', ['type' => 'finance', 'ledger' => 'deposits']))->assertUnprocessable();
    }

    public function test_xlsx_exports_all_filtered_pages_and_text_cannot_become_a_formula(): void
    {
        $this->owner->shop->update(['name' => '=HYPERLINK("https://example.test","test")']);
        for ($i = 0; $i < 30; $i++) {
            $this->order();
        }
        $report = $this->report();
        $this->assertCount(25, $report['rows']);
        $export = $this->export();
        $this->assertSame('ready', $export->status);
        $book = IOFactory::load(Storage::disk('local')->path($export->path));
        $details = $book->getSheetByName('Chi tiet');
        $this->assertSame(31, $details->getHighestRow());
        $this->assertSame(DataType::TYPE_STRING, $details->getCell('C2')->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $details->getCell('C2')->getValue());
        $this->assertSame(30, $export->row_count);
        $book->disconnectWorksheets();
    }

    public function test_finance_xlsx_keeps_decimal_max_as_numeric_cells_and_totals_exact(): void
    {
        $this->collection(Money::MAX);
        $export = $this->export('finance');
        $this->assertSame('ready', $export->status);
        $book = IOFactory::load(Storage::disk('local')->path($export->path));
        $sheet = $book->getSheetByName('Chi tiet');
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());
        $this->assertEquals(Money::MAX, $sheet->getCell('G2')->getValue());
        $this->assertSame('99.999.999.999.999.999đ', ReportValue::display('99999999999999999.00', 'money'));
        $book->disconnectWorksheets();
    }

    public function test_export_replay_returns_same_file_and_changed_payload_is_rejected(): void
    {
        $this->order();
        $token = (string) Str::uuid();
        $first = $this->export(token: $token);
        $again = $this->export(token: $token);
        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->path, $again->path);
        $this->assertSame(1, ExportLog::count());
        $this->expectException(ValidationException::class);
        $this->export(format: 'pdf', token: $token);
    }

    public function test_pdf_export_is_a_real_pdf_with_vietnamese_font_and_filtered_rows(): void
    {
        $this->collection();
        $export = $this->export('finance', 'pdf');
        $this->assertSame('ready', $export->status);
        $content = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('DejaVuSans', $content);
        $this->assertGreaterThan(1000, strlen($content));
        $this->assertSame(1, $export->row_count);
    }

    public function test_large_export_uses_job_and_repeated_delivery_does_not_rewrite_file(): void
    {
        Bus::fake([GenerateReportExport::class]);
        config(['reports.sync_rows' => 0]);
        $this->order();
        $export = $this->export();
        $this->assertSame('queued', $export->status);
        Bus::assertDispatched(GenerateReportExport::class, fn ($job) => $job->exportId === $export->id && $job->connection === 'reports');
        $job = new GenerateReportExport($export->id);
        $job->handle(app(ReportExportService::class));
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $hash = hash('sha256', Storage::disk('local')->get($export->path));
        $job->handle(app(ReportExportService::class));
        $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($export->path)));
    }

    public function test_queued_export_rechecks_scope_before_creating_private_file(): void
    {
        Bus::fake([GenerateReportExport::class]);
        config(['reports.sync_rows' => 0]);
        $this->order();
        $export = $this->export(user: $this->dispatcher);
        $this->dispatcher->warehouses()->detach();
        (new GenerateReportExport($export->id))->handle(app(ReportExportService::class));
        $this->assertSame('failed', $export->fresh()->status);
        Storage::disk('local')->assertMissing(app(ReportExportService::class)->path($export));
    }

    public function test_download_is_signed_owner_only_unexpired_and_current_scope_is_required(): void
    {
        $this->order();
        $export = $this->export(user: $this->dispatcher);
        $url = URL::temporarySignedRoute('reports.download', $export->expires_at, ['export' => $export]);
        $this->actingAs($this->dispatcher)->get($url)->assertOk()->assertDownload('bao-cao-'.$export->code.'.xlsx');
        $this->get(route('reports.download', $export))->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertForbidden();
        $this->actingAs($this->dispatcher)->get($url.'&unexpected=1')->assertForbidden();
        $this->dispatcher->warehouses()->detach();
        $this->get($url)->assertForbidden();
    }

    public function test_expired_export_is_not_downloadable_and_pruning_preserves_history(): void
    {
        $this->order();
        $export = $this->export();
        $path = $export->path;
        $this->travel(25)->hours();
        $url = URL::temporarySignedRoute('reports.download', now()->addHour(), ['export' => $export]);
        $this->actingAs($this->admin)->get($url)->assertGone();
        $this->artisan('reports:prune-exports')->assertSuccessful();
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertDatabaseHas('export_logs', ['id' => $export->id]);
        Storage::disk('local')->assertMissing($path);
        $this->artisan('reports:prune-exports')->assertSuccessful();
    }

    public function test_writer_failure_and_export_audit_failure_do_not_leave_files_or_ready_status(): void
    {
        $this->order();
        $this->mock(ReportFileWriter::class)->shouldReceive('write')->once()->andThrow(new \RuntimeException('Disk full'));
        $export = $this->export();
        $this->assertSame('failed', $export->status);
        $this->assertNull($export->path);
        $this->assertEmpty(Storage::disk('local')->allFiles('report-exports'));
        $this->mock(AuditService::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            $this->export();
            $this->fail('Request must roll back');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertSame(1, ExportLog::count());
    }

    public function test_date_range_identifiers_export_limit_and_unsupported_dimensions_are_validated(): void
    {
        $this->order();
        $this->actingAs($this->admin);
        foreach ([['group_by' => 'warehouse;DROP TABLE orders'], ['from' => 'bad'], ['from' => '2025-01-01', 'until' => '2026-10-30']] as $extra) {
            $this->getJson(route('reports.index', ['type' => 'operations'] + $extra))->assertUnprocessable();
        }
        $this->getJson(route('reports.index', ['type' => 'finance', 'ledger' => 'fees', 'group_by' => 'area']))->assertUnprocessable();
        $this->getJson(route('reports.index', ['type' => 'finance', 'ledger' => 'deposits', 'shop_id' => $this->owner->shop->id]))->assertUnprocessable();
        config(['reports.max_rows.xlsx' => 0]);
        $this->postJson(route('reports.store', 'operations'), $this->filters() + ['format' => 'xlsx', 'client_token' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, ExportLog::count());
    }

    public function test_report_pages_and_empty_states_render_without_per_order_queries(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index', 'operations'))->assertOk()->assertSee('Không có dữ liệu phù hợp.');
        for ($i = 0; $i < 30; $i++) {
            $this->order();
        }
        DB::enableQueryLog();
        $this->report();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(60, $count);
        foreach (['collections', 'fees', 'reconciliations', 'payments', 'deposits'] as $ledger) {
            $this->get(route('reports.index', ['type' => 'finance', 'ledger' => $ledger]))->assertOk();
        }
        $this->get(route('dashboard'))->assertOk()->assertSee('Vận hành 7 ngày');
        $this->get(route('reports.history'))->assertOk();
    }
}
