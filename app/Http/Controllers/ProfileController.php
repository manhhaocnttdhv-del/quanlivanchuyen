<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Services\IdentityService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('portal.profile', ['user' => $request->user()]);
    }

    public function update(ProfileRequest $request, IdentityService $service)
    {
        $service->updateProfile($request->user(), $request->validated());

        return back()->with('status', 'Đã cập nhật hồ sơ.');
    }

    public function password(PasswordRequest $request, IdentityService $service)
    {
        $service->changePassword($request->user(), $request->validated('password'));
        $request->session()->regenerate();

        return back()->with('status', 'Đã đổi mật khẩu. Các phiên đăng nhập khác sẽ hết hiệu lực.');
    }
}
