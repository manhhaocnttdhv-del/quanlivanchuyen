<?php

namespace App\Http\Controllers;

use App\Http\Requests\DraftSearchRequest;
use App\Http\Requests\DraftShipmentRequest;
use App\Services\DraftShipmentService;
use App\Services\ShippingQuoteService;

class DraftShipmentController extends Controller
{
    public function __construct(private DraftShipmentService $drafts, private ShippingQuoteService $calculator) {}

    public function index(DraftSearchRequest $request)
    {
        $search = $request->validated('q') ?? '';
        $drafts = collect($this->drafts->all())->sortByDesc('updated_at')
            ->filter(fn (array $draft) => $search === '' || mb_stripos($draft['code'].' '.$draft['recipient_name'].' '.$draft['recipient_phone'], $search) !== false);

        return view('customer.drafts', ['drafts' => $drafts, 'search' => $search, 'provinces' => config('shipping.provinces')]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(string $id)
    {
        return $this->form($this->drafts->find($id));
    }

    private function form(?array $draft = null)
    {
        return view('customer.draft-form', [
            'draft' => $draft,
            'quote' => $draft ? $this->calculator->calculate($draft) : null,
            'provinces' => config('shipping.provinces'),
            'services' => config('shipping.services'),
        ]);
    }

    public function store(DraftShipmentRequest $request)
    {
        $draft = $this->drafts->save($request->validated());

        return redirect()->route('drafts.edit', $draft['id'])
            ->with('status', 'Đã lưu bản nháp '.$draft['code'].'. Đơn chưa được gửi vận chuyển.');
    }

    public function update(DraftShipmentRequest $request, string $id)
    {
        $this->drafts->save($request->validated(), $id);

        return redirect()->route('drafts.edit', $id)->with('status', 'Đã cập nhật bản nháp. Đơn chưa được gửi vận chuyển.');
    }

    public function destroy(string $id)
    {
        $this->drafts->delete($id);

        return redirect()->route('drafts.index')->with('status', 'Đã xóa bản nháp.');
    }
}
