<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportExportRequest;
use App\Http\Requests\ReportRequest;
use App\Models\ExportLog;
use App\Policies\ReportPolicy;
use App\Services\FinancialReport;
use App\Services\OperationalReport;
use App\Services\ReportExportService;
use App\Services\ReportScope;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ReportController extends Controller
{
    public function home(Request $request, ReportPolicy $policy)
    {
        abort_unless($policy->any($request->user()), 403);

        return redirect()->route('reports.index', $policy->view($request->user(), 'operations') ? 'operations' : 'finance');
    }

    public function index(ReportRequest $request, string $type, ReportService $reports, ReportScope $scope, ReportPolicy $policy)
    {
        $filters = $request->filters();
        $ledgers = FinancialReport::LEDGERS;
        if (! $policy->staff($request->user(), 'finance')) {
            unset($ledgers['deposits']);
        }

        return view('portal.reports.index', ['report' => $reports->build($request->user(), $type, $filters),
            'choices' => $scope->choices($request->user(), $type), 'policy' => $policy,
            'metrics' => OperationalReport::METRICS, 'ledgers' => $ledgers]);
    }

    public function store(ReportExportRequest $request, string $type, ReportExportService $exports)
    {
        $data = $request->validated();
        $export = $exports->create($request->user(), $type, $request->filters(), $data['format'], $data['client_token']);

        return redirect()->route('reports.export', $export);
    }

    public function history(Request $request, ReportPolicy $policy)
    {
        abort_unless($policy->any($request->user()), 403);

        return view('portal.reports.history', ['exports' => ExportLog::where('user_id', $request->user()->id)->latest('id')->paginate(20)]);
    }

    public function export(Request $request, ExportLog $export, ReportExportService $exports)
    {
        $exports->authorize($request->user(), $export);
        $status = $export->expires_at->isPast() ? 'expired' : $export->status;
        $download = $status === 'ready' ? URL::temporarySignedRoute('reports.download', $export->expires_at, ['export' => $export]) : null;
        if ($request->expectsJson()) {
            return response()->json(['status' => $status, 'label' => ExportLog::STATUSES[$status], 'download_url' => $download,
                'row_count' => $export->row_count, 'error_message' => $export->error_message])->header('Cache-Control', 'private, no-store');
        }

        return view('portal.reports.export', compact('export', 'status', 'download'));
    }

    public function download(Request $request, ExportLog $export, ReportExportService $exports)
    {
        $exports->authorize($request->user(), $export);
        abort_unless($export->status === 'ready' && $export->expires_at->isFuture(), 410);
        abort_unless($export->path && Storage::disk('local')->exists($export->path), 404);

        return response()->download(Storage::disk('local')->path($export->path), 'bao-cao-'.$export->code.'.'.$export->format, [
            'Content-Type' => $export->format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }
}
