<?php

namespace App\Http\Controllers;

use App\Models\FeeVersion;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $visibleOrders = Order::visibleTo($request->user());

        return view('portal.dashboard', [
            'recentOrders' => $request->user()->can('viewAny', Order::class) ? (clone $visibleOrders)->latest('id')->limit(5)->get() : collect(),
            'shop' => $request->user()->shop()->with(['currentBankAccount', 'addresses.area'])->first(),
            'stats' => $request->user()->can('users.manage') ? [
                'Tài khoản hoạt động' => User::where('status', 'active')->count(),
                'Người gửi chờ duyệt' => Shop::where('status', 'pending')->count(),
                'Kho hoạt động' => Warehouse::where('is_active', true)->count(),
                'Biểu phí công bố' => FeeVersion::where('status', 'published')->count(),
            ] : [],
        ]);
    }
}
