<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Order;
use App\Models\Receivable;
use App\Models\Role;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\AdjustmentService;
use App\Services\CashDepositService;
use App\Services\DeliveryService;
use App\Services\DispatchService;
use App\Services\FeeReceiptService;
use App\Services\IncidentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\WarehouseService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DemoFinanceSeeder extends Seeder
{
    private User $owner;

    private User $admin;

    private User $accountant;

    private User $dispatcher;

    private User $clerk;

    private User $shipper;

    private User $support;

    private WarehouseLocation $location;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $password = env('DEMO_PASSWORD');
        if (! User::where('email', 'finance-admin@guiviet.test')->exists() && (! $password || strlen($password) < 10)) {
            throw new \RuntimeException('Đặt DEMO_PASSWORD dài ít nhất 10 ký tự để tạo Admin tài chính demo.');
        }
        // Hai tài khoản cùng vai trò Admin để giữ nguyên quy tắc người lập khác người duyệt.
        $financeAdmin = User::firstOrCreate(['email' => 'finance-admin@guiviet.test'], ['name' => 'Admin tài chính demo', 'phone' => '0900000007', 'password' => $password]);
        $financeAdmin->roles()->syncWithoutDetaching(Role::where('name', 'admin')->value('id'));
        foreach (['owner' => 'shop', 'admin' => 'admin', 'accountant' => 'finance-admin', 'dispatcher' => 'operations', 'clerk' => 'operations', 'shipper' => 'shipper', 'support' => 'operations'] as $property => $email) {
            $this->$property = User::where('email', $email.'@guiviet.test')->firstOrFail();
        }
        $this->location = WarehouseLocation::whereHas('warehouse', fn ($q) => $q->where('code', 'KHO-HN'))->where('code', 'A-01')->firstOrFail();
        $this->accountant->warehouses()->syncWithoutDetaching($this->location->warehouse_id);
        $this->support->warehouses()->syncWithoutDetaching($this->location->warehouse_id);
        foreach (['held', 'partial_deposit', 'submitted', 'partial_payment', 'fee_debt', 'adjustment'] as $index => $scenario) {
            $token = '00000006-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            if (Order::where('shop_id', $this->owner->shop->id)->where('client_token', $token)->exists()) {
                continue;
            }
            DB::transaction(function () use ($token, $scenario) {
                $order = $this->delivered($token, $scenario, $scenario === 'fee_debt' ? 10000 : 500000);
                if ($scenario === 'held') {
                    return;
                }
                $source = $order->collections()->where('type', 'cod')->sole();
                $deposits = app(CashDepositService::class);
                $deposit = $deposits->create($this->shipper, ['client_token' => $token, 'warehouse_id' => $order->warehouse_id, 'shipper_id' => $this->shipper->id, 'allocations' => [$source->id => (int) $source->amount], 'reason' => 'Demo Sprint 6: nộp COD về quỹ']);
                $received = $scenario === 'partial_deposit' ? 200000 : (int) $source->amount;
                $deposits->confirm($this->accountant, $deposit, ['revision' => 1, 'received_amount' => $received, 'allocations' => [$source->id => $received], 'transaction_reference' => 'DEMO-NT-'.$token, 'reason' => 'Demo: kiểm đếm thực nhận, ảnh kho chỉ minh họa'], $this->photo());
                if ($scenario === 'partial_deposit') {
                    return;
                }
                $fee = Receivable::where('order_id', $order->id)->where('type', 'shipping_fee')->sole();
                if ($scenario === 'adjustment') {
                    $this->adjustment($order, $token, $fee);

                    return;
                }
                $service = app(ReconciliationService::class);
                $rec = $service->create($this->accountant, ['client_token' => $token, 'warehouse_id' => $order->warehouse_id, 'shop_id' => $order->shop_id, 'period_start' => now()->subDay()->format('Y-m-d'), 'period_end' => now()->addDay()->format('Y-m-d'),
                    'cod' => [$source->id => (int) $source->amount], 'fees' => [$fee->id => (int) $fee->amount], 'reason' => 'Demo: đối soát COD trừ phí shop chịu']);
                $service->act($this->accountant, $rec, 'submit', ['revision' => 1, 'reason' => 'Demo: gửi người khác kiểm tra nguồn']);
                if ($scenario === 'submitted') {
                    return;
                }
                $service->act($this->admin, $rec->fresh(), 'approve', ['revision' => 2, 'reason' => 'Demo: người khác đã đối chiếu và duyệt']);
                if ($scenario === 'partial_payment') {
                    foreach (['failed' => 50000, 'succeeded' => 100000] as $status => $amount) {
                        app(PaymentService::class)->record($this->accountant, $rec, ['client_token' => $token, 'amount' => $amount, 'status' => $status, 'transaction_reference' => 'DEMO-TT-'.$status.'-'.$token, 'reason' => 'Demo: kết quả ngân hàng '.$status], $this->photo());
                        // Moi lan thu ngan hang la mot yeu cau rieng, khong tai su dung token.
                        $token = '00000006-0001-4000-8000-000000000004';
                    }
                } else {
                    $debt = Receivable::where('reconciliation_id', $rec->id)->sole();
                    $amount = min(5000, (int) $debt->amount);
                    app(FeeReceiptService::class)->record($this->accountant, ['client_token' => $token, 'warehouse_id' => $order->warehouse_id, 'shop_id' => $order->shop_id, 'amount' => $amount, 'allocations' => [$debt->id => $amount], 'transaction_reference' => 'DEMO-TP-'.$token, 'reason' => 'Demo: shop nộp một phần nợ phí'], $this->photo());
                }
            });
        }
    }

    private function delivered(string $token, string $scenario, int $cod): Order
    {
        $orders = app(OrderService::class);
        $dispatch = app(DispatchService::class);
        $stock = app(WarehouseService::class);
        $order = $orders->saveDraft($this->owner, ['client_token' => $token, 'pickup_address_id' => $this->owner->shop->addresses()->where('is_active', true)->value('id'),
            'area_id' => Area::where('code', 'HN')->value('id'), 'recipient_name' => 'Khách demo tài chính', 'recipient_phone' => '0987654321', 'recipient_address' => '60 Nguyễn Trãi, Hà Nội',
            'weight_grams' => 1000, 'parcel_count' => 1, 'service' => 'standard', 'cod_amount' => $cod, 'fee_payer' => 'sender', 'note' => 'Demo Sprint 6: '.$scenario, 'items' => [['name' => 'Sách demo tài chính', 'quantity' => 1]]]);
        $orders->submit($this->owner, $order, $order->revision);
        $orders->review($this->dispatcher, $order->fresh(), $order->fresh()->revision, 'approved', 'Demo: kiểm tra đủ thông tin tài chính');
        $task = $dispatch->assign($this->dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => 'pickup', 'shipper_id' => $this->shipper->id, 'reason' => 'Demo: lấy hàng nội thành']);
        $dispatch->pickup($this->shipper, $task, ['successful' => true, 'condition' => 'good', 'reason' => 'Demo: lấy đủ kiện'], 'logistics-evidence/demo-warehouse.jpg');
        $inbound = $stock->prepare($this->shipper, $task, 'inbound', 'Demo: bàn giao kiện về kho');
        $stock->confirm($this->clerk, $inbound, ['lines' => $inbound->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good', 'weight_grams' => 1000]])->all(), 'location_id' => $this->location->id, 'reason' => 'Demo: kho kiểm đếm đủ'], null);
        $task = $dispatch->assign($this->dispatcher, $order->fresh(), ['revision' => $order->fresh()->revision, 'type' => 'delivery', 'shipper_id' => $this->shipper->id, 'reason' => 'Demo: phân công giao hàng']);
        $outbound = $stock->prepare($this->clerk, $task, 'outbound', 'Demo: xuất kiện cho shipper');
        $stock->confirm($this->shipper, $outbound, ['lines' => $outbound->lines->mapWithKeys(fn ($line) => [$line->id => ['received' => true, 'condition' => 'good']])->all(), 'reason' => 'Demo: nhận đủ kiện để giao'], null);
        app(DeliveryService::class)->record($this->shipper, $task, ['client_token' => $token, 'outcome' => 'success', 'receiver_name' => 'Khách demo tài chính', 'actual_cod' => $cod, 'actual_fee' => 0, 'reason' => 'Demo: khách nhận hàng, ảnh kho chỉ minh họa'], $this->photo());

        return $order->fresh();
    }

    private function adjustment(Order $order, string $token, Receivable $fee): void
    {
        $cases = app(IncidentService::class);
        $incident = $cases->create($this->support, $order, ['client_token' => $token, 'type' => 'information', 'severity' => 'normal', 'title' => 'Demo giảm phí do giao chậm', 'description' => 'Xác minh thông tin và đề xuất giảm phí shop chịu', 'blocks_dispatch' => false, 'blocks_reconciliation' => true], $this->photo());
        $cases->act($this->support, $incident, 'propose', ['revision' => 1, 'outcome' => 'fee_reduction', 'amount' => 5000, 'conclusion' => 'Demo: giảm 5.000đ theo căn cứ đã xác minh', 'reason' => 'Demo: đã xác minh cùng shop']);
        $cases->act($this->admin, $incident->fresh(), 'review', ['revision' => 2, 'decision' => 'approved', 'reason' => 'Demo: duyệt căn cứ giảm phí']);
        app(AdjustmentService::class)->create($this->accountant, $order, ['client_token' => $token, 'kind' => 'fee_reduction', 'amount' => 5000, 'incident_id' => $incident->id, 'receivable_id' => $fee->id, 'reason' => 'Demo: lập điều chỉnh chờ người khác duyệt'], $this->photo());
    }

    private function photo(): UploadedFile
    {
        return new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'demo-minh-hoa.jpg', 'image/jpeg', null, true);
    }
}
