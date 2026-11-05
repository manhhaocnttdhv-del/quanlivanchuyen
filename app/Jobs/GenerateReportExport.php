<?php

namespace App\Jobs;

use App\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public int $exportId) {}

    public function handle(ReportExportService $service): void
    {
        $service->generate($this->exportId);
    }

    public function failed(?Throwable $error): void
    {
        app(ReportExportService::class)->fail($this->exportId);
    }
}
