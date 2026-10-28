<?php

namespace Tests\Unit;

use App\Services\ShippingQuoteService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShippingQuoteServiceTest extends TestCase
{
    public static function tariffCases(): array
    {
        return [
            'minimum weight' => ['HN', 'HN', 1, 'standard', 27000, 0, 'Nội tỉnh'],
            'base weight inclusive' => ['HN', 'HN', 500, 'standard', 27000, 0, 'Nội tỉnh'],
            'one gram over base' => ['HN', 'HN', 501, 'standard', 32000, 5000, 'Nội tỉnh'],
            'full extra step' => ['HN', 'HN', 1000, 'standard', 32000, 5000, 'Nội tỉnh'],
            'second extra step' => ['HN', 'HN', 1001, 'standard', 37000, 10000, 'Nội tỉnh'],
            'same region' => ['HN', 'HP', 500, 'standard', 32000, 0, 'Nội miền'],
            'different region' => ['HN', 'HCM', 500, 'standard', 38000, 0, 'Liên miền'],
            'express surcharge' => ['HN', 'HCM', 501, 'express', 57000, 7000, 'Liên miền'],
            'maximum supported weight' => ['HN', 'HCM', 20000, 'standard', 311000, 273000, 'Liên miền'],
        ];
    }

    #[DataProvider('tariffCases')]
    public function test_tariff_route_weight_boundaries_and_service_surcharge(
        string $origin, string $destination, int $weight, string $service,
        int $total, int $weightFee, string $route
    ): void {
        $quote = (new ShippingQuoteService)->calculate(compact('origin', 'destination', 'weight', 'service'));

        $this->assertSame($total, $quote['total']);
        $this->assertSame($weightFee, $quote['weight_fee']);
        $this->assertSame($route, $quote['route']);
        $this->assertIsInt($quote['total']);
        $this->assertSame(config('shipping.tariff_version'), $quote['version']);
    }
}
