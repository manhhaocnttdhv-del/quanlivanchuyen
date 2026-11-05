<?php

// Kiem tra hai worker xu ly cung su kien tren MySQL verify rieng, khong cham database ung dung.
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\NotificationDispatch;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\NotificationService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$database = $argv[1] ?? '';
if (! preg_match('/^quanlivanchuyen_verify_[0-9]+$/', $database)) {
    throw new RuntimeException('Phai dung database verify rieng da migrate.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Chi chay local/testing.');
}
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
if (($argv[2] ?? '') === 'deliver') {
    app(NotificationService::class)->deliver((int) $argv[3]);
    echo 'delivered';
    exit;
}

$app->make(CatalogSeeder::class)->run();
$person = function (string $role): User {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', $role)->value('id'));

    return $user->fresh();
};
$dispatcher = $person('operations');
$owner = $person('shop');
$depot = Warehouse::create(['code' => 'NT-'.Str::random(10), 'name' => 'Kho test thong bao',
    'area_id' => Area::where('code', 'HN')->value('id'), 'address' => 'Dia chi test']);
$dispatcher->warehouses()->attach($depot);
$shop = new Shop(['name' => 'Shop test thong bao', 'area_id' => $depot->area_id, 'address' => 'Dia chi test']);
$shop->user_id = $owner->id;
$shop->status = 'approved';
$shop->save();
$order = Order::create(['shop_id' => $shop->id, 'created_by' => $owner->id, 'warehouse_id' => $depot->id,
    'area_id' => $depot->area_id, 'tracking_code' => 'GV'.Str::ulid(), 'status' => 'submitted', 'submitted_at' => now(),
    'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64)]);

$beforeJobs = DB::table('jobs')->where('queue', 'notifications')->count();
try {
    DB::transaction(function () use ($owner, $order, $beforeJobs) {
        app(AuditService::class)->record($owner, $order, 'order.submitted');
        if (DB::table('jobs')->where('queue', 'notifications')->count() !== $beforeJobs) {
            throw new RuntimeException('Job was dispatched before commit');
        }
        throw new LogicException('Intentional rollback');
    });
} catch (LogicException) {
    if (AuditLog::where('subject_type', Order::class)->where('subject_id', $order->id)->exists()) {
        throw new RuntimeException('Rollback left a success audit');
    }
}

app(AuditService::class)->record($owner, $order, 'order.submitted');
$log = AuditLog::where('subject_type', Order::class)->where('subject_id', $order->id)->sole();
$dispatch = NotificationDispatch::where('audit_log_id', $log->id)->sole();
if (DB::table('jobs')->where('queue', 'notifications')->count() !== $beforeJobs + 1) {
    throw new RuntimeException('Committed event did not create exactly one database job');
}
$workers = [];
foreach (range(1, 2) as $i) {
    $worker = new Process([PHP_BINARY, __FILE__, $database, 'deliver', (string) $dispatch->id]);
    $worker->setTimeout(60)->start();
    $workers[] = $worker;
}
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException('Worker failed: '.$worker->getErrorOutput());
    }
}
if ($dispatcher->notifications()->where('audit_log_id', $log->id)->count() !== 1 || ! $dispatch->fresh()->delivered_at) {
    throw new RuntimeException('Duplicate or missing notification');
}
echo 'PASS: real commit/rollback and two competing MySQL workers produce one notification per recipient'.PHP_EOL;
