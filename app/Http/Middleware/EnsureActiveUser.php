<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ($user->status !== 'active' || $request->session()->get('auth_version', $user->auth_version) !== $user->auth_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['identity' => 'Tài khoản bị khóa hoặc phiên đã hết hiệu lực. Vui lòng đăng nhập lại.']);
        }

        return $next($request);
    }
}
