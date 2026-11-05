<?php

namespace App\Http\Controllers;

use App\Http\Requests\BankAccountRequest;
use App\Http\Requests\PickupAddressRequest;
use App\Http\Requests\ShopProfileRequest;
use App\Models\Area;
use App\Models\PickupAddress;
use App\Services\ShopService;
use Illuminate\Http\Request;

/** Shop tự quản hồ sơ/địa chỉ; thay đổi ngân hàng tạo phiên bản mới và chờ xác minh lại. */
class ShopController extends Controller
{
    public function profile(Request $request)
    {
        $shop = $request->user()->shop()->with(['currentBankAccount', 'bankAccounts', 'addresses.area'])->firstOrFail();

        return view('portal.shop.profile', ['shop' => $shop, 'areas' => Area::where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    public function update(ShopProfileRequest $request, ShopService $service)
    {
        $shop = $request->user()->shop()->firstOrFail();
        $this->authorize('update', $shop);
        $service->update($request->user(), $shop, $request->validated());

        return back()->with('status', 'Đã lưu hồ sơ. Thông tin thay đổi cần được duyệt lại.');
    }

    public function bank(BankAccountRequest $request, ShopService $service)
    {
        $shop = $request->user()->shop()->firstOrFail();
        $this->authorize('update', $shop);
        $service->replaceBank($request->user(), $shop, $request->validated());

        return back()->with('status', 'Đã tạo phiên bản tài khoản ngân hàng mới, đang chờ xác minh.');
    }

    public function address(Request $request, ?PickupAddress $pickup = null)
    {
        if ($pickup) {
            $this->authorize('update', $pickup);
        }

        return view('portal.shop.address', ['record' => $pickup, 'areas' => Area::where('is_active', true)->orderBy('name')->pluck('name', 'id')]);
    }

    public function saveAddress(PickupAddressRequest $request, ShopService $service, ?PickupAddress $pickup = null)
    {
        // Shop lấy từ tài khoản; Policy chặn sửa địa chỉ của shop khác, Service giữ một mặc định.
        $shop = $request->user()->shop()->firstOrFail();
        if ($pickup) {
            $this->authorize('update', $pickup);
        }
        $service->saveAddress($request->user(), $shop, $request->validated(), $pickup);

        return redirect()->route('shop.profile')->with('status', 'Đã lưu địa chỉ lấy hàng.');
    }
}
