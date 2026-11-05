<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Area;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ShopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function register()
    {
        return view('auth.register', [
            'areas' => Area::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'warehouses' => Warehouse::where('is_active', true)->whereHas('area', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')->get(['area_id', 'code', 'name', 'address']),
        ]);
    }

    public function authenticate(LoginRequest $request)
    {
        $data = $request->validated();
        $key = 'login:'.hash('sha256', Str::lower($data['identity']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Thử đăng nhập quá nhiều lần. Vui lòng đợi một phút.');
        }
        $field = filter_var($data['identity'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = User::where($field, $data['identity'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['identity' => 'Thông tin đăng nhập không đúng hoặc tài khoản đã bị khóa.']);
        }
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return redirect()->intended(route('dashboard'));
    }

    public function store(RegisterRequest $request, ShopService $service)
    {
        $user = $service->register($request->validated());
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return redirect()->route('shop.profile')->with('status', 'Đăng ký thành công. Hồ sơ người gửi đang chờ duyệt.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
