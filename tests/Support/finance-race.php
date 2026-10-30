<?php

// Hai tien trinh tranh khoa MySQL that; chi chay tren database verify rieng.
use App\Models\Area;
use App\Models\CashDeposit;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCollection;
use App\Models\DeliveryTask;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\Reconciliation;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashDepositService;
use App\Services\FeeReceiptService;
use App\Services\FinanceLedger;
use App\Services\PaymentService;
use App\Services\ReceivableService;
use App\Services\ReconciliationService;
use App\Services\ShopService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$database = $argv[1] ?? '';
if (! preg_match('/^quanlivanchuyen_verify_[0-9]+$/', $database)) {
    throw new RuntimeException('Phai dung database verify rieng da migrate.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Chi chay trong local/testing.');
}
config(['database.connections.mysql.database' => $database, 'filesystems.disks.local.root' => storage_path('framework/testing/finance-race')]);
DB::purge('mysql');
$mode = $argv[2] ?? 'parent';
$proof = fn () => new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'race.jpg', 'image/jpeg', null, true);
if ($mode !== 'parent') {
    $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    $actor = User::findOrFail($data['actor']);
    try {
        match ($mode) {
            'deposit' => app(CashDepositService::class)->create($actor, $data['input']),
            'confirm' => app(CashDepositService::class)->confirm($actor, CashDeposit::findOrFail($data['document']), $data['input'], $proof()),
            'reconcile' => app(ReconciliationService::class)->create($actor, $data['input']),
            'approve' => app(ReconciliationService::class)->act($actor, Reconciliation::findOrFail($data['document']), 'approve', $data['input']),
            'pay' => app(PaymentService::class)->record($actor, Reconciliation::findOrFail($data['document']), $data['input'], $proof()),
            'receipt' => app(FeeReceiptService::class)->record($actor, $data['input'], $proof()),
        };
        echo 'accepted';
    } catch (ValidationException) {
        echo 'rejected';
    }
    exit;
}

$app->make(CatalogSeeder::class)->run();
$person = function (string $role): User {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $role)->value('id'));

    return $user->fresh();
};
$admin = $person('admin');
$secondAdmin = $person('admin');
$accountant = $person('accountant');
$shipper = $person('shipper');
$owner = $person('shop');
$warehouse = Warehouse::create(['code' => 'FIN-'.Str::random(10), 'name' => 'Kho kiem tra dong thoi', 'area_id' => Area::where('code', 'HN')->value('id'), 'address' => 'Dia chi kiem thu']);
foreach ([$accountant, $shipper] as $user) {
    $user->warehouses()->attach($warehouse);
}
$shop = new Shop(['name' => 'Shop kiem tra tai chinh', 'area_id' => $warehouse->area_id, 'address' => 'Dia chi kiem thu']);
$shop->user_id = $owner->id;
$shop->status = 'approved';
$shop->save();
app(ShopService::class)->replaceBank($owner, $shop, ['bank_name' => 'Ngan hang test', 'holder_name' => 'SHOP TEST', 'account_number' => '1234567890']);
app(ShopService::class)->verifyBank($admin, $shop->fresh()->currentBankAccount, 'Kiem tra tai khoan nhan COD');
$order = Order::create(['shop_id' => $shop->id, 'created_by' => $owner->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
    'status' => 'delivered', 'delivered_at' => now(), 'warehouse_id' => $warehouse->id, 'area_id' => $warehouse->area_id, 'origin_area_id' => $warehouse->area_id, 'parcel_count' => 1, 'weight_grams' => 500, 'shipping_fee' => 30000, 'cod_amount' => 500000, 'fee_payer' => 'sender']);
$task = DeliveryTask::create(['order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'shipper_id' => $shipper->id, 'created_by' => $admin->id, 'type' => 'delivery', 'status' => 'completed', 'reason' => 'Nguon kiem tra tai chinh']);
$attempt = DeliveryAttempt::create(['order_id' => $order->id, 'delivery_task_id' => $task->id, 'shipper_id' => $shipper->id, 'type' => 'delivery', 'outcome' => 'success', 'attempt_number' => 1, 'reason' => 'Nguon kiem tra tai chinh', 'client_token' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'attempted_at' => now()]);
$collection = DeliveryCollection::create(['order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'shipper_id' => $shipper->id, 'delivery_attempt_id' => $attempt->id, 'type' => 'cod', 'amount' => 500000, 'expected_amount' => 500000, 'financial_status' => 'matched', 'idempotency_key' => hash('sha256', Str::uuid()), 'collected_at' => now()]);
app(ReceivableService::class)->accrue($order);
$fee = Receivable::where('order_id', $order->id)->sole();
$race = function (string $operation, array $jobs, array $expected = ['accepted', 'rejected']) use ($database): void {
    $workers = [];
    DB::beginTransaction();
    DB::table('business_locks')->where('name', 'finance-posting')->lockForUpdate()->firstOrFail();
    try {
        foreach ($jobs as $data) {
            $worker = new Process([PHP_BINARY, __FILE__, $database, $operation, json_encode($data, JSON_THROW_ON_ERROR)], base_path());
            $worker->setTimeout(45)->start();
            $workers[] = $worker;
        }
        usleep(600000);
    } finally {
        DB::commit();
    }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException($worker->getErrorOutput().$worker->getOutput());
        }
        $results[] = trim($worker->getOutput());
    }
    sort($results);
    sort($expected);
    if ($results !== $expected) {
        throw new RuntimeException($operation.': '.json_encode($results));
    }
    echo $operation.': OK'.PHP_EOL;
};
$base = ['warehouse_id' => $warehouse->id, 'reason' => 'Kiem tra dong thoi bang hai tien trinh'];
$input = $base + ['client_token' => (string) Str::uuid(), 'shipper_id' => $shipper->id, 'allocations' => [$collection->id => 500000]];
$job = ['actor' => $shipper->id, 'input' => $input];
$race('deposit', [$job, $job], ['accepted', 'accepted']);
$deposit = CashDeposit::where('shipper_id', $shipper->id)->sole();
$job = ['actor' => $accountant->id, 'document' => $deposit->id, 'input' => ['revision' => 1, 'received_amount' => 500000, 'allocations' => [$collection->id => 500000], 'transaction_reference' => 'RACE-NT-'.Str::uuid(), 'reason' => $base['reason']]];
$race('confirm', [$job, $job], ['accepted', 'accepted']);
$input = $base + ['shop_id' => $shop->id, 'period_start' => now()->subDay()->format('Y-m-d'), 'period_end' => now()->addDay()->format('Y-m-d'), 'cod' => [$collection->id => 500000], 'fees' => [], 'adjustment_ids' => []];
$race('reconcile', array_map(fn () => ['actor' => $accountant->id, 'input' => $input + ['client_token' => (string) Str::uuid()]], [1, 2]));
$rec = Reconciliation::where('shop_id', $shop->id)->sole();
app(ReconciliationService::class)->act($accountant, $rec, 'submit', ['revision' => 1, 'reason' => $base['reason']]);
$race('approve', array_map(fn ($user) => ['actor' => $user->id, 'document' => $rec->id, 'input' => ['revision' => 2, 'reason' => $base['reason']]], [$admin, $secondAdmin]));
$race('pay', array_map(fn () => ['actor' => $accountant->id, 'document' => $rec->id, 'input' => ['client_token' => (string) Str::uuid(), 'amount' => 300000, 'status' => 'succeeded', 'transaction_reference' => 'RACE-TT-'.Str::uuid(), 'reason' => $base['reason']]], [1, 2]));
$race('receipt', array_map(fn () => ['actor' => $accountant->id, 'input' => $base + ['client_token' => (string) Str::uuid(), 'shop_id' => $shop->id, 'amount' => 20000, 'allocations' => [$fee->id => 20000], 'transaction_reference' => 'RACE-TP-'.Str::uuid()]], [1, 2]));
$ledger = app(FinanceLedger::class);
if ($ledger->deposited($collection->id) !== 500000 || $ledger->paid($rec->id) !== 300000 || $ledger->feeAvailable($fee) !== 10000 || $deposit->allocations()->count() !== 1) {
    throw new RuntimeException('Tong tien sau tranh khoa khong khop.');
}
echo 'finance race totals: OK'.PHP_EOL;
