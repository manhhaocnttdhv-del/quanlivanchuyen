<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        // Tao ID tai server, khong tin gia tri header do client tu gui.
        $id = (string) Str::uuid();
        $request->attributes->set('audit_request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}
