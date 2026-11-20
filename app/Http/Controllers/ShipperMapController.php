<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShipperMapRequest;
use App\Services\ShipperMapService;

class ShipperMapController extends Controller
{
    public function index(ShipperMapRequest $request, ShipperMapService $map)
    {
        $filters = $request->validated();

        return response()->view('portal.delivery.map', $map->page($request->user(), $filters) + ['filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
