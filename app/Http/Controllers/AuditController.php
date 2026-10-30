<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditFilterRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditSearch;
use App\Services\AuditService;
use App\Services\AuditSubject;
use App\Support\AuditData;
use Illuminate\Http\Request;

/** Tra cứu nhật ký toàn hệ thống; AuditFilterRequest/Policy giới hạn quyền admin. */
class AuditController extends Controller
{
    public function index(AuditFilterRequest $request, AuditSearch $search)
    {
        return view('portal.audit', [
            'logs' => $search->query($request->validated())->paginate(25)->withQueryString(),
            'actors' => User::orderBy('name')->pluck('name', 'id'), 'subjects' => AuditSubject::LABELS,
        ]);
    }

    public function show(Request $request, AuditLog $log, AuditSubject $subjects, AuditService $audit)
    {
        $this->authorize('view', $log);

        // Log cũ cũng được lọc lại trước hiển thị; link hồ sơ phải qua quyền hiện tại.

        return view('portal.audit-show', [
            'log' => $log->load('actor'), 'sourceUrl' => $subjects->url($request->user(), $log),
            'before' => $audit->sanitize($log->old_values ?? []), 'after' => $audit->sanitize($log->new_values ?? []),
            'reason' => AuditData::text($log->reason),
        ]);
    }
}
