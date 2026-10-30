<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Area;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ShopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Xác thực tài khoản; ShopService chịu trách nhiệm tạo shop và lịch sử đăng ký. */
class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function register()
    {
        return view('auth.register', ['areas' => Area::where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    public function authenticate(LoginRequest $request, AuditService $audit)
    {
        $data = $request->validated();
        // Giới hạn theo danh tính + IP; thông báo chung tránh tiết lộ tài khoản có tồn tại.
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
        $audit->record($user, $user, 'auth.logged_in', [], ['status' => $user->status], 'Đăng nhập thành công');
        // Đổi session ID chống session fixation; auth_version dùng thu hồi các phiên cũ.
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

        return redirect()->route('shop.profile')->with('status', 'Đăng ký thành công. Hồ sơ cửa hàng đang chờ duyệt.');
    }

    public function logout(Request $request, AuditService $audit)
    {
        // Ghi actor trước khi xóa phiên; hủy cả dữ liệu phiên và token CSRF khi đăng xuất.
        $audit->record($request->user(), $request->user(), 'auth.logged_out', [], [], 'Người dùng đăng xuất');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
