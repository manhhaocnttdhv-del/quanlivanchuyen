<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest;
use App\Http\Requests\TrackingRequest;
use App\Services\DraftShipmentService;
use App\Services\ShippingQuoteService;

class CustomerPortalController extends Controller
{
    public function index()
    {
        return view('customer.home', [
            'provinces' => config('shipping.provinces'),
            'services' => config('shipping.services'),
            'rates' => config('shipping.routes'),
        ]);
    }

    public function estimate(QuoteRequest $request, ShippingQuoteService $calculator)
    {
        $quote = $calculator->calculate($request->validated());

        return $request->expectsJson() ? response()->json($quote)
            : redirect()->to(route('home').'?tool=estimate#cong-cu')->withInput()->with('quote', $quote);
    }

    public function track(TrackingRequest $request, DraftShipmentService $drafts)
    {
        $input = $request->validated();
        $result = $drafts->track($input['tracking_code'], $input['phone_suffix']);

        return $request->expectsJson() ? response()->json($result)
            : redirect()->to(route('home').'#cong-cu')->withInput()->with('tracking', $result);
    }
}
