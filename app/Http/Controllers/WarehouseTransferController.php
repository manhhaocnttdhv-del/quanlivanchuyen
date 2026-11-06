<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseTransferRequest;
use App\Models\Order;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferLine;
use App\Policies\WarehouseTransferPolicy;
use App\Services\LogisticsEvidence;
use App\Services\WarehouseTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WarehouseTransferController extends Controller
{
    public function __construct(private WarehouseTransferPolicy $policy) {}

    public function index(Request $request)
    {
        abort_unless($this->policy->viewAny($request->user()), 403);

        return view('portal.transfers.index', ['transfers' => WarehouseTransfer::visibleTo($request->user())
            ->with(['order', 'source', 'destination'])->withCount(['lines', 'lines as received_count' => fn ($q) => $q->whereNotNull('received_at')])
            ->orderByRaw("status IN ('received', 'closed_loss') ASC")->latest('id')->paginate(25)]);
    }

    public function store(WarehouseTransferRequest $request, Order $order, WarehouseTransferService $service)
    {
        $transfer = $service->dispatch($request->user(), $order, $request->validated());

        return to_route('transfers.show', $transfer)->with('success', 'Đã xuất chuyển kho. Chờ kho nhận kiểm đếm từng kiện.');
    }

    public function show(Request $request, WarehouseTransfer $transfer)
    {
        abort_unless($this->policy->view($request->user(), $transfer), 403);

        return view('portal.transfers.show', ['transfer' => $transfer->load(['order', 'source', 'destination', 'creator', 'lines.item', 'lines.receiver', 'lines.location']),
            'policy' => $this->policy, 'locations' => WarehouseLocation::where('warehouse_id', $transfer->destination_warehouse_id)->where('is_active', true)->pluck('name', 'id')]);
    }

    public function receive(WarehouseTransferRequest $request, WarehouseTransfer $transfer, WarehouseTransferService $service, LogisticsEvidence $evidence)
    {
        $evidence->run($request->file('evidence'), fn ($path) => $service->receive($request->user(), $transfer, $request->validated(), $path));

        $message = $transfer->fresh()->status === 'closed_loss'
            ? 'Đã nhận phần hàng còn lại; chuyến kết thúc có kiện mất. Vận đơn tiếp tục giữ xử lý.'
            : 'Đã ghi nhận kiểm đếm. Chỉ phân công khi đã nhập đủ kiện và xử lý sai lệch.';

        return to_route('transfers.show', $transfer)->with('success', $message);
    }

    public function evidence(Request $request, WarehouseTransfer $transfer, WarehouseTransferLine $line)
    {
        abort_unless($line->warehouse_transfer_id === $transfer->id && $this->policy->view($request->user(), $transfer), 403);
        abort_unless($line->evidence_path && Storage::disk('local')->exists($line->evidence_path), 404);

        return response()->file(Storage::disk('local')->path($line->evidence_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
