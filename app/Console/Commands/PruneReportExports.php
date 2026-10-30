<?php

namespace App\Console\Commands;

use App\Models\ExportLog;
use App\Services\AuditService;
use App\Services\ReportExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneReportExports extends Command
{
    protected $signature = 'reports:prune-exports';

    protected $description = 'Delete expired private report files while preserving export history';

    public function handle(ReportExportService $exports, AuditService $audit): int
    {
        $count = 0;
        ExportLog::where('expires_at', '<=', now())->where('status', '!=', 'expired')->chunkById(100, function ($logs) use ($exports, $audit, &$count) {
            foreach ($logs as $log) {
                DB::transaction(function () use ($log, $exports, $audit, &$count) {
                    $log = ExportLog::whereKey($log->id)->lockForUpdate()->firstOrFail();
                    Storage::disk('local')->delete($exports->path($log));
                    $log->update(['status' => 'expired', 'path' => null]);
                    $audit->record(null, $log, 'report.export_expired', [], $log->toArray());
                    $count++;
                });
            }
        });
        $this->info('Expired files removed: '.$count);

        return self::SUCCESS;
    }
}
