<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderActionRequest;
use App\Http\Requests\OrderChangeReviewRequest;
use App\Http\Requests\OrderFilterRequest;
use App\Http\Requests\OrderRequest;
use App\Models\Area;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\BarcodeGeneratorSVG;

class OrderController extends Controller
{
    public function index(OrderFilterRequest $request)
    {
        $query = $this->filtered($request);
        $orders = $query->with(['shop', 'area', 'warehouse', 'shipper', 'pendingChange'])->latest('id')->paginate(15)->withQueryString();
        $visible = Order::visibleTo($request->user());

        return view('portal.orders.index', [
            'orders' => $orders, 'statuses' => Order::STATUSES,
            'areas' => Area::orderBy('name')->pluck('name', 'id'),
            'shops' => Shop::whereIn('id', (clone $visible)->select('shop_id'))->orderBy('name')->pluck('name', 'id'),
            'shippers' => User::whereIn('id', (clone $visible)->whereNotNull('assigned_shipper_id')->select('assigned_shipper_id'))->pluck('name', 'id'),
            'counts' => (clone $visible)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', Order::class);

        return $this->form($request, null);
    }

    public function edit(Request $request, Order $order)
    {
        $this->authorize('update', $order);
        abort_unless(in_array($order->status, [...Order::EDITABLE, 'approved']), 422, 'Trạng thái hiện tại không được sửa.');

        return $this->form($request, $order->load(['items', 'pendingChange']));
    }

    private function form(Request $request, ?Order $order)
    {
        $shop = $request->user()->shop()->firstOrFail();

        return view('portal.orders.form', [
            'order' => $order, 'shop' => $shop, 'clientToken' => (string) Str::uuid(),
            'pickups' => $shop->addresses()->with('area')->where('is_active', true)->whereHas('area', fn ($q) => $q->where('is_active', true))->get(),
            'areas' => Area::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(OrderRequest $request, OrderService $service)
    {
        $order = $service->saveDraft($request->user(), $request->validated());

        return redirect()->route('orders.show', $order)->with('status', 'Đã lưu nháp. Kiểm tra thông tin trước khi gửi duyệt.');
    }

    public function update(OrderRequest $request, Order $order, OrderService $service)
    {
        $service->saveDraft($request->user(), $request->validated(), $order);

        return redirect()->route('orders.show', $order)->with('status', 'Đã lưu thông tin. Cước được tính lại khi gửi.');
    }

    public function show(Order $order, OrderService $service)
    {
        $this->authorize('view', $order);
        $order->load(['shop', 'area', 'warehouse', 'items', 'pickupAddress.area', 'histories.actor', 'changeRequests.creator', 'changeRequests.reviewer', 'pendingChange']);
        $quote = $order->fee_snapshot;
        $previewErrors = [];
        if (in_array($order->status, Order::EDITABLE)) {
            try {
                $quote = $service->preview($order);
            } catch (ValidationException $exception) {
                $previewErrors = $exception->validator->errors()->all();
            }
        }

        return view('portal.orders.show', compact('order', 'quote', 'previewErrors'));
    }

    public function submit(OrderActionRequest $request, Order $order, OrderService $service)
    {
        $service->submit($request->user(), $order, $request->integer('revision'));

        return back()->with('status', 'Đã gửi duyệt và chốt địa chỉ, biểu phí.');
    }

    public function review(OrderActionRequest $request, Order $order, OrderService $service)
    {
        $service->review($request->user(), $order, $request->integer('revision'), $request->validated('decision'), $request->validated('reason'));

        return back()->with('status', 'Đã lưu kết quả xét duyệt.');
    }

    public function cancel(OrderActionRequest $request, Order $order, OrderService $service)
    {
        $service->cancel($request->user(), $order, $request->integer('revision'), $request->validated('reason'));

        return back()->with('status', 'Đã hủy vận đơn và lưu lịch sử.');
    }

    public function change(OrderRequest $request, Order $order, OrderService $service)
    {
        $service->requestChange($request->user(), $order, $request->integer('revision'), 'edit', $request->validated(), $request->validated('reason'));

        return redirect()->route('orders.show', $order)->with('status', 'Đã gửi đề nghị chỉnh sửa, chờ điều phối xét duyệt.');
    }

    public function requestCancel(OrderActionRequest $request, Order $order, OrderService $service)
    {
        $service->requestChange($request->user(), $order, $request->integer('revision'), 'cancel', [], $request->validated('reason'));

        return back()->with('status', 'Đã gửi đề nghị hủy.');
    }

    public function reviewChange(OrderChangeReviewRequest $request, OrderChangeRequest $change, OrderService $service)
    {
        $service->reviewChange($request->user(), $change, $request->validated('decision'), $request->validated('reason'));

        return back()->with('status', 'Đã lưu kết quả xử lý đề nghị.');
    }

    public function label(Order $order)
    {
        $this->authorize('print', $order);
        $order->load(['items', 'pendingChange']);
        abort_if($order->pendingChange, 422, 'Đang có đề nghị thay đổi, chưa thể in tem.');
        $barcode = (new BarcodeGeneratorSVG)->getBarcode($order->tracking_code, BarcodeGeneratorSVG::TYPE_CODE_128, 1.3, 55);

        return response()->view('portal.orders.label', compact('order', 'barcode'))->header('Cache-Control', 'private, no-store');
    }

    public function manifest(OrderFilterRequest $request)
    {
        $orders = $this->filtered($request)->whereNotIn('status', ['draft', 'cancelled', 'rejected', 'needs_info'])->whereDoesntHave('pendingChange')
            ->with('shop')->orderBy('id')->limit(101)->get();
        if ($orders->count() > 100) {
            throw ValidationException::withMessages(['q' => 'Bảng kê tối đa 100 vận đơn. Thu hẹp bộ lọc trước khi in.']);
        }
        foreach ($orders as $order) {
            $this->authorize('print', $order);
        }

        return response()->view('portal.orders.manifest', compact('orders'))->header('Cache-Control', 'private, no-store');
    }

    private function filtered(OrderFilterRequest $request)
    {
        return Order::visibleTo($request->user())->filtered($request->validated());
    }
}
