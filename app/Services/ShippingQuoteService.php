<?php

namespace App\Services;

class ShippingQuoteService
{
    public function calculate(array $input): array
    {
        $provinces = config('shipping.provinces');
        if ($input['origin'] === $input['destination']) {
            $route = 'local';
        } elseif ($provinces[$input['origin']]['region'] === $provinces[$input['destination']]['region']) {
            $route = 'regional';
        } else {
            $route = 'national';
        }
        $rate = config("shipping.routes.$route");
        $service = config('shipping.services.'.$input['service']);
        $extraWeight = max(0, (int) $input['weight'] - config('shipping.base_weight_grams'));
        $step = config('shipping.step_weight_grams');

        // Integer ceiling: each started 500 g is charged as a full step; never use floats for VND.
        $steps = intdiv($extraWeight + $step - 1, $step);
        $weightFee = $steps * $rate['step'];

        return [
            'route' => $rate['label'],
            'service' => $service['label'],
            'base_fee' => $rate['base'],
            'weight_fee' => $weightFee,
            'service_fee' => $service['surcharge'],
            'total' => $rate['base'] + $weightFee + $service['surcharge'],
            'days' => $input['service'] === 'express' ? '1 - 2 ngày' : $rate['days'],
            'version' => config('shipping.tariff_version'),
        ];
    }
}
