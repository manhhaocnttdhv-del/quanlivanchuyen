<?php

namespace App\Http\Controllers;

use App\Models\FeeVersion;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return view('portal.dashboard', [
            'shop' => $request->user()->shop()->with(['currentBankAccount', 'addresses.area'])->first(),
            'stats' => $request->user()->can('users.manage') ? [
                'Tài khoản hoạt động' => User::where('status', 'active')->count(),
                'Shop chờ duyệt' => Shop::where('status', 'pending')->count(),
                'Kho hoạt động' => Warehouse::where('is_active', true)->count(),
                'Biểu phí công bố' => FeeVersion::where('status', 'published')->count(),
            ] : [],
        ]);
    }
}
