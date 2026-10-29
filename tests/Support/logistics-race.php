<?php

// Kiểm tra khóa MySQL bằng hai tiến trình thật; chỉ chạy trên database verify riêng.
use App\Models\Area;
use App\Models\DeliveryTask;
use App\Models\HandoverDocument;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\WarehouseService;
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
    throw new RuntimeException('Phải chỉ định database verify riêng đã migrate.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Chỉ kiểm tra trong local/testing.');
}
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
$mode = $argv[2] ?? 'parent';

if ($mode !== 'parent') {
    $input = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    $actor = User::findOrFail($input['actor']);
    try {
        if ($mode === 'assign') {
            app(DispatchService::class)->assign($actor, Order::findOrFail($input['order']), ['revision' => $input['revision'], 'type' => 'pickup', 'shipper_id' => $input['shipper'], 'reason' => 'Kiểm tra phân công đồng thời']);
        } elseif ($mode === 'pickup') {
            app(DispatchService::class)->pickup($actor, DeliveryTask::findOrFail($input['task']), ['successful' => true, 'condition' => 'good', 'reason' => 'Kiểm tra xác nhận lấy đồng thời'], 'race-proof.png');
        } elseif (in_array($mode, ['deliver', 'delivery-failure'])) {
            $task = DeliveryTask::findOrFail($input['task']);
            $data = ['client_token' => $input['token'], 'reason' => 'Kiểm tra kết quả giao đồng thời'];
            if ($mode === 'deliver') {
                $data += ['outcome' => 'success', 'receiver_name' => 'Người nhận kiểm thử', 'actual_cod' => 0, 'actual_fee' => 0];
                $photo = new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'race.jpg', 'image/jpeg', null, true);
            } else {
                $data += ['outcome' => 'failed', 'reason_code' => 'absent'];
                $photo = null;
            }
            app(DeliveryService::class)->record($actor, $task, $data, $photo);
        } else {
            $doc = HandoverDocument::findOrFail($input['document']);
            $lines = $doc->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 500]])->all();
            app(WarehouseService::class)->confirm($actor, $doc, ['lines' => $lines, 'location_id' => $input['location'], 'reason' => 'Kiểm tra xác nhận bàn giao đồng thời'], null);
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
$dispatcher = $person('dispatcher');
$shipper = $person('shipper');
$clerk = $person('warehouse');
$owner = $person('shop');
$area = Area::where('code', 'HN')->firstOrFail();
$warehouse = Warehouse::create(['code' => 'RACE-'.Str::random(10), 'name' => 'Kho kiểm tra đồng thời', 'area_id' => $area->id, 'address' => 'Địa chỉ kiểm thử', 'is_active' => true]);
foreach ([$dispatcher, $shipper, $clerk] as $user) {
    $user->warehouses()->attach($warehouse);
}
$location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A-01', 'name' => 'Kệ kiểm thử']);
$shop = new Shop(['name' => 'Shop kiểm thử', 'area_id' => $area->id, 'address' => 'Địa chỉ kiểm thử']);
$shop->user_id = $owner->id;
$shop->status = 'approved';
$shop->save();
$order = Order::create(['shop_id' => $shop->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64),
    'created_by' => $owner->id, 'status' => 'approved', 'warehouse_id' => $warehouse->id, 'area_id' => $area->id, 'origin_area_id' => $area->id, 'parcel_count' => 2, 'weight_grams' => 1000]);

$race = function (string $mode, array $input, array $expected = ['accepted', 'rejected']) use ($database, $shop): array {
    $workers = [];
    DB::beginTransaction();
    Shop::lockForUpdate()->findOrFail($shop->id);
    try {
        for ($index = 0; $index < 2; $index++) {
            $workerInput = $input;
            if (isset($input['tokens'])) {
                $workerInput['token'] = $input['tokens'][$index];
            }
            $worker = new Process([PHP_BINARY, __FILE__, $database, $mode, json_encode($workerInput, JSON_THROW_ON_ERROR)], base_path());
            $worker->setTimeout(30)->start();
            $workers[] = $worker;
        }
        // Giữ khóa đủ lâu để cả hai worker cùng xếp hàng trước giao dịch đầu tiên.
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
    if ($results !== $expected) {
        throw new RuntimeException('Kết quả đồng thời không hợp lệ: '.json_encode($results));
    }

    return $results;
};
$results['assign'] = $race('assign', ['actor' => $dispatcher->id, 'order' => $order->id, 'revision' => 1, 'shipper' => $shipper->id]);
$task = $order->tasks()->firstOrFail();
$results['pickup'] = $race('pickup', ['actor' => $shipper->id, 'task' => $task->id]);
$service = app(WarehouseService::class);
$doc = $service->prepare($shipper, $task, 'inbound', 'Nhập kho kiểm tra đồng thời');
$results['receipt'] = $race('confirm', ['actor' => $clerk->id, 'document' => $doc->id, 'location' => $location->id]);
$delivery = app(DispatchService::class)->assign($dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => 'delivery', 'shipper_id' => $shipper->id, 'reason' => 'Phân công giao kiểm thử']);
$doc = $service->prepare($clerk, $delivery, 'outbound', 'Xuất kho kiểm tra đồng thời');
$results['issue'] = $race('confirm', ['actor' => $shipper->id, 'document' => $doc->id, 'location' => $location->id]);
if ($order->parcels()->count() !== 2 || $order->parcels()->where('holder_user_id', $shipper->id)->count() !== 2 || $order->histories()->where('event', 'handover_confirmed')->count() !== 2) {
    throw new RuntimeException('Chuỗi người giữ hoặc lịch sử không khớp.');
}
$results['delivery_different_tokens'] = $race('deliver', ['actor' => $shipper->id, 'task' => $delivery->id, 'tokens' => [(string) Str::uuid(), (string) Str::uuid()]]);
$attempt = $delivery->deliveryAttempt()->firstOrFail();
$results['delivery_exact_replay'] = $race('deliver', ['actor' => $shipper->id, 'task' => $delivery->id, 'tokens' => [$attempt->getRawOriginal('client_token'), $attempt->getRawOriginal('client_token')]], ['accepted', 'accepted']);
if ($order->fresh()->status !== 'delivered' || $order->fresh()->delivery_attempt_count !== 1 || $order->collections()->count() !== 2 || $order->histories()->where('event', 'delivery_succeeded')->count() !== 1) {
    throw new RuntimeException('Giao đồng thời đã tạo trùng tiền/lượt/lịch sử.');
}
// Fixture độc lập để hai worker cùng ghi thất bại cho một nhiệm vụ chưa có kết quả.
$failedOrder = $order->fresh()->replicate();
$failedOrder->fill(['tracking_code' => 'GV'.Str::upper((string) Str::ulid()), 'client_token' => (string) Str::uuid(), 'status' => 'out_for_delivery', 'delivery_attempt_count' => 0, 'assigned_shipper_id' => $shipper->id, 'custody_status' => 'shipper', 'delivered_at' => null])->save();
$failedTask = $failedOrder->tasks()->create(['warehouse_id' => $warehouse->id, 'shipper_id' => $shipper->id, 'created_by' => $dispatcher->id, 'type' => 'delivery', 'status' => 'in_custody', 'reason' => 'Fixture kiểm tra thất bại đồng thời']);
foreach ($order->parcels as $item) {
    $copy = $item->replicate();
    $copy->fill(['order_id' => $failedOrder->id, 'holder_type' => 'shipper', 'holder_user_id' => $shipper->id])->save();
}
$results['delivery_failure'] = $race('delivery-failure', ['actor' => $shipper->id, 'task' => $failedTask->id, 'tokens' => [(string) Str::uuid(), (string) Str::uuid()]]);
if ($failedOrder->fresh()->delivery_attempt_count !== 1 || $failedOrder->collections()->exists() || $failedOrder->fresh()->custody_status !== 'shipper') {
    throw new RuntimeException('Thất bại đồng thời làm sai lượt/tiền/người giữ.');
}
echo json_encode(['database' => $database, 'races' => $results, 'parcels' => 2, 'collections' => 2, 'delivery_events' => 1], JSON_PRETTY_PRINT).PHP_EOL;
