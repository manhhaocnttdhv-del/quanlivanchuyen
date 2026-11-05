<?php

namespace App\Services;

use App\Jobs\DeliverBusinessNotification;
use App\Models\AuditLog;
use App\Models\NotificationDispatch;
use App\Support\NotificationEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NotificationService
{
    public function __construct(private NotificationAudience $audience, private NotificationTarget $targets) {}

    public function stage(AuditLog $log): void
    {
        if (NotificationEvents::supports($log)) {
            $dispatch = NotificationDispatch::create(['audit_log_id' => $log->id]);
            DB::afterCommit(fn () => $this->enqueue($dispatch->id));
        }
    }

    public function enqueue(int $id): bool
    {
        $claimed = NotificationDispatch::whereKey($id)->whereNull('delivered_at')
            ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(10)))
            ->update(['queued_at' => now()]);
        if (! $claimed) {
            return false;
        }
        try {
            DeliverBusinessNotification::dispatch($id)->onConnection('notifications')->onQueue('notifications');
        } catch (Throwable $error) {
            // Giao dich nghiep vu da commit: giu outbox de lenh phuc hoi co the phat lai.
            NotificationDispatch::whereKey($id)->update(['queued_at' => null]);
            logger()->warning('Could not enqueue notification', ['dispatch_id' => $id, 'exception' => $error::class]);
        }

        return true;
    }

    public function deliver(int $id): void
    {
        DB::transaction(function () use ($id) {
            $dispatch = NotificationDispatch::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($dispatch->delivered_at) {
                return;
            }
            $description = $this->audience->describe($dispatch->auditLog);
            if ($description) {
                $this->audience->candidates($dispatch->auditLog, $description['target'])->chunkById(100, function ($users) use ($description, $dispatch) {
                    foreach ($users as $user) {
                        if ($this->targets->url($user, $description['target']) === null) {
                            continue;
                        }
                        $user->notifications()->firstOrCreate(['audit_log_id' => $dispatch->audit_log_id], [
                            'id' => (string) Str::uuid(), 'type' => 'business_event', 'data' => $description['data'],
                        ]);
                    }
                });
            }
            // Khoa outbox va unique recipient/event bao ve ca retry lan hai worker cung xu ly.
            $dispatch->update(['delivered_at' => now()]);
        }, 3);
    }
}
