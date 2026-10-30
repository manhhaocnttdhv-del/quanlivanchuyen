<?php

namespace App\Services;

use App\Jobs\GenerateReportExport;
use App\Models\ExportLog;
use App\Models\User;
use App\Policies\ReportPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReportExportService
{
    public function __construct(private ReportService $reports, private ReportScope $scope, private ReportPolicy $policy, private ReportFileWriter $writer, private AuditService $audit) {}

    public function create(User $user, string $type, array $filters, string $format, string $token): ExportLog
    {
        Validator::make(['format' => $format, 'client_token' => $token], ['format' => ['required', 'in:xlsx,pdf'], 'client_token' => ['required', 'uuid']])->validate();
        abort_unless($this->policy->view($user, $type), 403);
        $this->scope->validate($user, $type, $filters);
        ksort($filters);
        $hash = hash('sha256', json_encode([$type, $format, $filters], JSON_THROW_ON_ERROR));
        [$export, $created, $count] = DB::transaction(function () use ($user, $type, $filters, $format, $token, $hash) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = ExportLog::where('user_id', $user->id)->where('client_token', $token)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['format' => 'Mã yêu cầu đã dùng với bộ lọc hoặc định dạng khác.']);
                }

                return [$existing, false, 0];
            }
            $count = $this->reports->query($user, $type, $filters)->count();
            if ($count > config('reports.max_rows.'.$format, 0)) {
                throw ValidationException::withMessages(['format' => 'Tối đa '.config('reports.max_rows.'.$format).' dòng mỗi tệp. Hãy thu hẹp bộ lọc.']);
            }
            $export = ExportLog::create(['code' => 'EX'.Str::upper((string) Str::ulid()), 'user_id' => $user->id,
                'report_type' => $type, 'format' => $format, 'filters' => $filters, 'scope_hash' => $this->scope->hash($user, $type),
                'client_token' => $token, 'request_hash' => $hash, 'status' => 'queued', 'expires_at' => now()->addHours(config('reports.expires_hours'))]);
            $this->audit->record($user, $export, 'report.export_requested', [], $export->toArray());

            return [$export, true, $count];
        });
        if ($created) {
            if ($count <= config('reports.sync_rows')) {
                $this->generate($export->id);
            } else {
                GenerateReportExport::dispatch($export->id)->onConnection('reports')->onQueue('reports')->afterCommit();
            }
        }

        return $export->fresh();
    }

    public function generate(int $id): void
    {
        // Chi mot worker duoc tao tep; retry/dispatch lap khong viet de tep da san sang.
        if (! ExportLog::whereKey($id)->where('status', 'queued')->where('expires_at', '>', now())->update(['status' => 'processing'])) {
            return;
        }
        $export = ExportLog::findOrFail($id);
        $path = $this->path($export);
        try {
            $user = $export->user()->firstOrFail();
            $this->authorize($user, $export);
            $report = $this->reports->build($user, $export->report_type, $export->filters, config('reports.max_rows.'.$export->format));
            $this->writer->write($export, $report, $path);
            if (! Storage::disk('local')->exists($path) || Storage::disk('local')->size($path) === 0) {
                throw new \RuntimeException('Report file was not written');
            }
            DB::transaction(function () use ($export, $report, $path) {
                $current = ExportLog::whereKey($export->id)->lockForUpdate()->firstOrFail();
                $this->authorize($current->user()->firstOrFail(), $current);
                abort_unless($current->status === 'processing' && $current->expires_at->isFuture(), 410);
                $current->update(['status' => 'ready', 'path' => $path, 'row_count' => $report['rows']->count(), 'generated_at' => now()]);
                $this->audit->record($current->user, $current, 'report.export_ready', [], $current->toArray());
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            $this->fail($id);
            logger()->warning('Report export failed', ['export_id' => $id, 'exception' => $error::class]);
        }
    }

    public function authorize(User $user, ExportLog $export): void
    {
        abort_unless($this->policy->export($user, $export) && hash_equals($export->scope_hash, $this->scope->hash($user, $export->report_type)), 403);
    }

    public function fail(int $id): void
    {
        $export = ExportLog::find($id);
        if ($export && in_array($export->status, ['queued', 'processing'])) {
            Storage::disk('local')->delete($this->path($export));
            $export->update(['status' => 'failed', 'error_message' => 'Không thể tạo tệp. Hãy kiểm tra quyền truy cập hoặc thu hẹp bộ lọc rồi tạo lại.']);
        }
    }

    public function path(ExportLog $export): string
    {
        return 'report-exports/'.$export->code.'.'.$export->format;
    }
}
