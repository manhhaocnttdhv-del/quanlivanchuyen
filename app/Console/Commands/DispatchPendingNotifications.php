<?php

namespace App\Console\Commands;

use App\Models\NotificationDispatch;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class DispatchPendingNotifications extends Command
{
    protected $signature = 'notifications:dispatch-pending';

    protected $description = 'Recover pending notification dispatches after an unavailable queue';

    public function handle(NotificationService $notifications): int
    {
        $count = 0;
        NotificationDispatch::whereNull('delivered_at')->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(10)))
            ->chunkById(100, function ($dispatches) use ($notifications, &$count) {
                foreach ($dispatches as $dispatch) {
                    $count += (int) $notifications->enqueue($dispatch->id);
                }
            });
        $this->info('Notification dispatches queued: '.$count);

        return self::SUCCESS;
    }
}
