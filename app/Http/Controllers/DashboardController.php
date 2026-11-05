<?php

namespace App\Http\Controllers;

use App\Models\FeeVersion;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\ReportPolicy;
use App\Services\OperationalReport;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ReportPolicy $reportPolicy, OperationalReport $reports)
    {
        $visibleOrders = Order::visibleTo($request->user());
        $reportStats = [];
        if ($reportPolicy->view($request->user(), 'operations')) {
            $filters = ['from' => now('Asia/Ho_Chi_Minh')->subDays(6)->toDateString(), 'until' => now('Asia/Ho_Chi_Minh')->toDateString(), 'group_by' => 'warehouse', 'metric' => 'created'];
            foreach (['created', 'delivered', 'inventory', 'overdue'] as $metric) {
                $reportStats[] = ['label' => OperationalReport::METRICS[$metric], 'value' => $reports->query($request->user(), $filters, $metric)->count(),
                    'url' => route('reports.index', ['type' => 'operations'] + array_replace($filters, ['metric' => $metric]))];
            }
        }

        return view('portal.dashboard', [
            'reportStats' => $reportStats,
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
