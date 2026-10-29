<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Policies\ComplaintPolicy;
use App\Policies\IncidentPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CaseAttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment, IncidentPolicy $incidents, ComplaintPolicy $complaints)
    {
        if ($attachment->incident_action_id) {
            abort_unless($incidents->view($request->user(), $attachment->incidentAction->incident), 403);
        } else {
            $message = $attachment->complaintMessage;
            abort_unless($complaints->view($request->user(), $message->complaint) && (! $message->is_internal || $complaints->manage($request->user(), $message->complaint)), 403);
        }
        $path = $attachment->getRawOriginal('path');
        abort_unless(Storage::disk('local')->exists($path), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Type' => $attachment->mime];

        return $attachment->mime === 'application/pdf'
            ? response()->download(Storage::disk('local')->path($path), 'bang-chung-'.$attachment->id.'.pdf', $headers)->setPrivate()
            : response()->file(Storage::disk('local')->path($path), $headers)->setPrivate();
    }
}
