<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\FinancePosting;
use App\Services\ReceivableService;
use Illuminate\Console\Command;

class AccrueFees extends Command
{
    protected $signature = 'finance:accrue-fees';

    protected $description = 'Ghi nhận phí shop chịu từ đơn đã giao/hoàn, không nhân đôi';

    public function handle(FinancePosting $posting, ReceivableService $fees): int
    {
        Order::whereIn('status', ['delivered', 'returned'])->orderBy('id')->chunkById(100, function ($orders) use ($posting, $fees) {
            foreach ($orders as $order) {
                $posting->run(fn () => $fees->accrue($posting->orders([$order->id])[$order->id]));
            }
        });
        $this->info('Đã ghi nhận phí còn thiếu từ các đơn giao/hoàn; không thay tiền thu gốc.');

        return self::SUCCESS;
    }
}
