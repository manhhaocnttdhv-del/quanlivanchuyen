<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalFilterRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\ShopReviewRequest;
use App\Models\Shop;
use App\Models\ShopBankAccount;
use App\Services\ShopService;

/** Nhân viên xét hồ sơ shop/xác minh ngân hàng; hai quyền độc lập và kiểm bằng Policy từng bản ghi. */
class ShopReviewController extends Controller
{
    public function index(PortalFilterRequest $request)
    {
        $shops = Shop::with(['user', 'area', 'currentBankAccount'])
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('q'), fn ($q, $search) => $q->where('name', 'like', "%$search%"))
            ->latest('id')->paginate(15)->withQueryString();

        return view('portal.shops.index', compact('shops'));
    }

    public function show(Shop $shop)
    {
        $this->authorize('view', $shop);

        return view('portal.shops.show', ['shop' => $shop->load(['user', 'area', 'addresses.area', 'bankAccounts'])]);
    }

    public function review(ShopReviewRequest $request, Shop $shop, ShopService $service)
    {
        $this->authorize('review', $shop);
        $service->review($request->user(), $shop, $request->validated());

        return back()->with('status', 'Đã cập nhật kết quả xét duyệt.');
    }

    public function verify(ReasonRequest $request, ShopBankAccount $bank, ShopService $service)
    {
        // Chỉ phiên bản ngân hàng hiện hành được xác minh; lịch sử phiên bản cũ vẫn được giữ.
        $this->authorize('verify', $bank);
        $service->verifyBank($request->user(), $bank, $request->validated('reason'));

        return back()->with('status', 'Đã xác minh phiên bản tài khoản ngân hàng.');
    }
}
