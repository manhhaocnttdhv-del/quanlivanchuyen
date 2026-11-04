<?php

namespace Tests;

use App\Models\Area;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ShopService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

abstract class DatabaseTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CatalogSeeder::class);
    }

    protected function person(string $role = 'admin'): User
    {
        $user = User::factory()->create(['phone' => '0'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT), 'password' => 'TestPassword!2026'])->refresh();
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }

    protected function warehouse(string $code = 'HN'): Warehouse
    {
        return Warehouse::create(['code' => 'KHO-'.uniqid(), 'name' => 'Kho kiểm thử', 'address' => 'Địa chỉ kiểm thử', 'area_id' => Area::where('code', $code)->value('id'), 'is_active' => true]);
    }

    protected function shopOwner(bool $approved = true): User
    {
        $user = $this->person('shop');
        $shop = new Shop(['name' => 'Shop kiểm thử', 'address' => 'Địa chỉ kiểm thử', 'area_id' => Area::where('code', 'HN')->value('id')]);
        $shop->user()->associate($user);
        $shop->status = $approved ? 'approved' : 'pending';
        $shop->save();
        app(ShopService::class)->saveAddress($user, $shop, [
            'contact_name' => $user->name, 'phone' => $user->phone, 'area_id' => $shop->area_id, 'address' => '10 Đường kiểm thử', 'is_active' => true, 'is_default' => true,
        ]);

        return $user->fresh();
    }

    protected function feeData(string $start = '2026-01-01T00:00', ?string $until = '2028-01-01T00:00'): array
    {
        $rules = [];
        foreach (config('shipping.routes') as $route => $rate) {
            foreach (config('shipping.services') as $service => $option) {
                $rules[] = ['route' => $route, 'service' => $service, 'min_weight_grams' => 1, 'max_weight_grams' => 20000, 'base_weight_grams' => 500, 'step_weight_grams' => 500, 'base_fee' => $rate['base'] + $option['surcharge'], 'step_fee' => $rate['step'], 'max_cod' => 20000000];
            }
        }

        return ['name' => 'Biểu phí kiểm thử', 'active_from' => $start, 'active_until' => $until, 'rules' => $rules];
    }
}
