<?php

// Hai tien trinh cung cho khoa don tren database verify, khong dung database live/test suite.
use App\Models\Area;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Services\WarehouseTransferService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$database = $argv[1] ?? '';
if (! preg_match('/^quanlivanchuyen_verify_[0-9]+$/', $database)) {
    throw new RuntimeException('Chi chay tren database verify da migrate.');
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Chi chay trong local/testing.');
}
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
$mode = $argv[2] ?? 'parent';
if ($mode !== 'parent') {
    $input = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    try {
        $actor = User::findOrFail($input['actor']);
        $service = app(WarehouseTransferService::class);
        if ($mode === 'dispatch') {
            $service->dispatch($actor, Order::findOrFail($input['order']), $input['data']);
        } else {
            $service->receive($actor, WarehouseTransfer::findOrFail($input['transfer']), $input['data'], null);
        }
        echo 'accepted';
    } catch (ValidationException) {
        echo 'rejected';
    }
    exit;
}

$app->make(CatalogSeeder::class)->run();
$person = function (): User {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', 'operations')->value('id'));

    return $user->fresh();
};
$sender = $person();
$receiver = $person();
$source = Warehouse::create(['code' => 'RACE-'.Str::random(10), 'name' => 'Kho gui race', 'area_id' => Area::where('code', 'HN')->value('id'), 'address' => 'Dia chi kiem thu']);
$destination = Warehouse::create(['code' => 'RACE-'.Str::random(10), 'name' => 'Kho nhan race', 'area_id' => Area::where('code', 'HCM')->value('id'), 'address' => 'Dia chi kiem thu']);
$sender->warehouses()->attach($source);
$receiver->warehouses()->attach($destination);
$location = WarehouseLocation::create(['warehouse_id' => $destination->id, 'code' => 'A', 'name' => 'Ke kiem thu']);
$shop = new Shop(['name' => 'Shop race', 'address' => 'Dia chi kiem thu', 'area_id' => $source->area_id]);
$shop->user_id = $sender->id;
$shop->status = 'approved';
$shop->save();
$order = Order::create(['shop_id' => $shop->id, 'created_by' => $sender->id, 'tracking_code' => 'GV'.Str::upper((string) Str::ulid()),
    'client_token' => (string) Str::uuid(), 'creation_hash' => str_repeat('a', 64), 'status' => 'at_warehouse', 'custody_status' => 'warehouse',
    'warehouse_id' => $source->id, 'origin_warehouse_id' => $source->id, 'origin_area_id' => $source->area_id, 'area_id' => $destination->area_id, 'parcel_count' => 2]);
foreach ([1, 2] as $number) {
    $order->parcels()->create(['parcel_number' => $number, 'holder_type' => 'warehouse', 'warehouse_id' => $source->id, 'condition' => 'good']);
}
$race = function (string $mode, array $input) use ($database, $shop): array {
    $workers = [];
    DB::beginTransaction();
    Shop::lockForUpdate()->findOrFail($shop->id);
    try {
        foreach ([1, 2] as $index) {
            $worker = new Process([PHP_BINARY, __FILE__, $database, $mode, json_encode($input, JSON_THROW_ON_ERROR)], base_path());
            $worker->setTimeout(30)->start();
            $workers[] = $worker;
        }
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
    if ($results !== ['accepted', 'rejected']) {
        throw new RuntimeException('Ket qua race sai: '.json_encode($results));
    }

    return $results;
};
$results['dispatch'] = $race('dispatch', ['actor' => $sender->id, 'order' => $order->id, 'data' => ['revision' => 1,
    'destination_warehouse_id' => $destination->id, 'carrier' => 'Tai xe kiem thu', 'transport_reference' => 'RACE-01', 'reason' => 'Kiem tra xuat chuyen dong thoi']]);
$transfer = $order->transfers()->sole();
$results['receipt'] = $race('receive', ['actor' => $receiver->id, 'transfer' => $transfer->id, 'data' => [
    'revision' => $order->fresh()->revision, 'location_id' => $location->id, 'reason' => 'Kiem tra nhan chuyen dong thoi',
    'lines' => $transfer->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all()]]);
if ($order->transfers()->count() !== 1 || $order->histories()->where('event', 'transfer_received')->count() !== 1
    || $order->histories()->where('event', 'transfer_dispatched')->count() !== 1
    || $order->parcels()->where('holder_type', 'warehouse')->where('warehouse_id', $destination->id)->count() !== 2) {
    throw new RuntimeException('Race da tao trung phieu/lich su hoac sai custody.');
}
echo json_encode(['database' => $database, 'races' => $results, 'transfers' => 1, 'received_parcels' => 2], JSON_PRETTY_PRINT).PHP_EOL;
