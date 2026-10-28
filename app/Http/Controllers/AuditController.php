<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalFilterRequest;
use App\Models\AuditLog;

class AuditController extends Controller
{
    public function index(PortalFilterRequest $request)
    {
        $logs = AuditLog::with('actor')->when($request->validated('q'), fn ($q, $search) => $q->where(fn ($q) => $q->where('event', 'like', "%$search%")->orWhere('subject_id', $search)))
            ->latest('id')->paginate(25)->withQueryString();

        return view('portal.audit', compact('logs'));
    }
}
