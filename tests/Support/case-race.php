<?php

// Hai worker that tranh khoa MySQL; khong cho chay tren database ung dung.
use App\Models\Area;
use App\Models\Attachment;
use App\Models\Complaint;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ComplaintService;
use App\Services\DispatchService;
use App\Services\IncidentService;
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
set_exception_handler(function (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Chi kiem tra trong local/testing.');
}
config(['database.connections.mysql.database' => $database, 'filesystems.disks.local.root' => storage_path('framework/testing/case-race')]);
DB::purge('mysql');
$mode = $argv[2] ?? 'parent';

if ($mode !== 'parent') {
    $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    $actor = User::findOrFail($data['actor']);
    try {
        if ($mode === 'create') {
            app(IncidentService::class)->create($actor, Order::findOrFail($data['order']), $data['input'], new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'race.jpg', 'image/jpeg', null, true));
        } elseif (in_array($mode, ['propose', 'review'])) {
            app(IncidentService::class)->act($actor, Incident::findOrFail($data['incident']), $mode, $data['input']);
        } elseif ($mode === 'message') {
            app(ComplaintService::class)->act($actor, Complaint::findOrFail($data['complaint']), 'message', $data['input']);
        } else {
            app(DispatchService::class)->assign($actor, Order::findOrFail($data['order']), $data['input']);
        }
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
$support = $person('operations');
$approver = $person('operations');
$secondApprover = $person('operations');
$shipper = $person('shipper');
$owner = $person('shop');
$area = Area::where('code', 'HN')->firstOrFail();
$warehouse = Warehouse::create(['code' => 'CASE-'.Str::random(10), 'name' => 'Kho kiem tra dong thoi', 'area_id' => $area->id, 'address' => 'Dia chi kiem thu', 'is_active' => true]);
foreach ([$support, $approver, $secondApprover, $shipper] as $user) {
    $user->warehouses()->attach($warehouse);
}
$shop = new Shop(['name' => 'Shop kiem tra dong thoi', 'area_id' => $area->id, 'address' => 'Dia chi kiem thu']);
$shop->user_id = $owner->id;
$shop->status = 'approved';
$shop->save();
$newOrder = fn () => Order::create(['shop_id' => $shop->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
    'created_by' => $owner->id, 'status' => 'approved', 'submitted_at' => now(), 'warehouse_id' => $warehouse->id, 'area_id' => $area->id, 'origin_area_id' => $area->id, 'parcel_count' => 1, 'weight_grams' => 500]);
$order = $newOrder();
$input = ['client_token' => (string) Str::uuid(), 'title' => 'Su co kiem tra dong thoi', 'description' => 'Bang chung va can cu kiem tra khoa MySQL', 'type' => 'information', 'severity' => 'high', 'blocks_dispatch' => true, 'blocks_reconciliation' => false];

$race = function (array $jobs, ?array $expected = ['accepted', 'rejected']) use ($database, $shop): array {
    $workers = [];
    DB::beginTransaction();
    Shop::lockForUpdate()->findOrFail($shop->id);
    try {
        foreach ($jobs as [$mode, $data]) {
            $worker = new Process([PHP_BINARY, __FILE__, $database, $mode, json_encode($data, JSON_THROW_ON_ERROR)], base_path());
            $worker->setTimeout(30)->start();
            $workers[] = $worker;
        }
        // Cho ca hai worker xep hang tai cung shop truoc khi nha khoa.
        usleep(800000);
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
    if ($expected !== null && $results !== $expected) {
        throw new RuntimeException('Ket qua dong thoi khong hop le: '.json_encode($results));
    }

    return $results;
};
$create = ['actor' => $support->id, 'order' => $order->id, 'input' => $input];
$results['create_exact_replay'] = $race([['create', $create], ['create', $create]], ['accepted', 'accepted']);
$incident = $order->incidents()->sole();
if ($incident->actions()->count() !== 1 || Attachment::whereHas('incidentAction', fn ($q) => $q->where('incident_id', $incident->id))->count() !== 1) {
    throw new RuntimeException('Replay da tao trung ho so/anh/lich su.');
}
$proposal = ['actor' => $support->id, 'incident' => $incident->id, 'input' => ['revision' => 1, 'reason' => 'Da xac minh du can cu', 'outcome' => 'no_financial', 'amount' => 0, 'conclusion' => 'Khong phat sinh nghia vu tai chinh sau xac minh']];
$results['proposal'] = $race([['propose', $proposal], ['propose', $proposal]]);
$review = ['actor' => $approver->id, 'incident' => $incident->id, 'input' => ['revision' => 2, 'reason' => 'Nguoi khac duyet ket luan', 'decision' => 'approved']];
$results['two_reviewers'] = $race([['review', $review], ['review', array_replace($review, ['actor' => $secondApprover->id])]]);
if ($incident->fresh()->status !== 'closed' || $incident->actions()->where('type', 'approved')->count() !== 1 || $incident->fresh()->blocks_dispatch) {
    throw new RuntimeException('Ket luan/khoa sau duyet khong nhat quan.');
}
$complaint = app(ComplaintService::class)->create($owner, $order, ['client_token' => (string) Str::uuid(), 'title' => 'Khieu nai kiem tra dong thoi', 'type' => 'delay', 'description' => 'Can kiem tra request phan hoi cung phien ban']);
$message = ['actor' => $support->id, 'complaint' => $complaint->id, 'input' => ['revision' => 1, 'is_internal' => true, 'body' => 'Ghi nhan noi bo voi cung phien ban']];
$results['same_revision_message'] = $race([['message', $message], ['message', $message]]);
if ($complaint->messages()->count() !== 2) {
    throw new RuntimeException('Da ghi trung phan hoi cung revision.');
}
$order = $newOrder();
$create = ['actor' => $support->id, 'order' => $order->id, 'input' => array_replace($input, ['client_token' => (string) Str::uuid()])];
$assign = ['actor' => $approver->id, 'order' => $order->id, 'input' => ['revision' => 1, 'type' => 'pickup', 'shipper_id' => $shipper->id, 'reason' => 'Phan cong dong thoi voi khoa su co']];
$results['hold_vs_dispatch'] = $race([['create', $create], ['assign', $assign]], null);
if (! in_array($results['hold_vs_dispatch'], [['accepted', 'accepted'], ['accepted', 'rejected']], true) || $order->incidents()->where('blocks_dispatch', true)->count() !== 1 || $order->tasks()->count() > 1) {
    throw new RuntimeException('Khoa/phat sinh nhiem vu khong nhat quan.');
}
// Neu phan cong thang truoc, khoa moi van chan shipper nhan hang.
if ($task = $order->tasks()->first()) {
    try {
        app(DispatchService::class)->pickup($shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Kiem tra khong vuot khoa moi'], 'race.png');
        throw new RuntimeException('Nhan hang da vuot khoa su co.');
    } catch (ValidationException) {
        $results['pickup_after_hold'] = 'rejected';
    }
}
if ($order->parcels()->exists()) {
    throw new RuntimeException('Phat sinh kien du da khoa.');
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
