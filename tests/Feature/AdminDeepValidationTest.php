<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\FeeVersion;
use App\Models\Reconciliation;
use App\Services\FeeVersionService;
use App\Services\ReconciliationService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

class AdminDeepValidationTest extends DatabaseTestCase
{
    public function test_date_filters_reject_arrays_and_keep_valid_range_checks(): void
    {
        $this->actingAs($this->person());
        foreach ($this->filteredPages() as $url) {
            foreach ([['2026-11-01'], ['date' => ['2026-11-01']]] as $invalid) {
                $this->getJson($url.'?'.http_build_query(['from' => $invalid, 'until' => '2026-11-20']))
                    ->assertUnprocessable()->assertJsonValidationErrors('from');
                $this->getJson($url.'?'.http_build_query(['from' => '2026-11-01', 'until' => $invalid]))
                    ->assertUnprocessable()->assertJsonValidationErrors('until');
            }
            $this->getJson($url.'?from=2026-11-20&until=2026-11-01')
                ->assertUnprocessable()->assertJsonValidationErrors('until');
            $this->get($url.'?from=2026-11-20&until=2026-11-20')->assertOk();
        }
    }

    public function test_optional_date_filters_accept_an_end_date_without_a_start_date(): void
    {
        $this->actingAs($this->person());
        foreach (['users.index', 'shops.index', 'orders.index', 'audit.index', 'finance.index', 'delivery.collections'] as $name) {
            $this->get(route($name, ['until' => '2026-11-20']))->assertOk();
        }
        $this->actingAs($this->person('shipper'))->getJson(route('delivery.mine', [
            'from' => ['2026-11-01'], 'until' => '2026-11-20',
        ]))->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_fee_dates_reject_arrays_without_creating_or_modifying_a_version(): void
    {
        $admin = $this->person();
        $draft = app(FeeVersionService::class)->save($admin, $this->feeData())->refresh();
        $before = $draft->getAttributes();
        $ruleIds = $draft->rules->modelKeys();
        $logs = AuditLog::count();
        $this->actingAs($admin);
        foreach ([['2026-11-01T00:00'], ['date' => ['2026-11-01T00:00']]] as $invalid) {
            foreach (['active_from', 'active_until'] as $field) {
                $data = array_replace($this->feeData(), [$field => $invalid]);
                $this->postJson(route('fees.store'), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->putJson(route('fees.update', $draft), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->postJson(route('fees.store'), $this->feeData('2026-11-01T00:00', '2026-11-01T00:00'))
            ->assertUnprocessable()->assertJsonValidationErrors('active_until');
        $this->assertSame(1, FeeVersion::count());
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $this->assertSame($ruleIds, $draft->fresh()->rules->modelKeys());
        $this->assertSame($logs, AuditLog::count());
    }

    public function test_reconciliation_dates_reject_arrays_at_http_and_service_boundaries(): void
    {
        $admin = $this->person();
        $owner = $this->shopOwner();
        $warehouse = $this->warehouse();
        $logs = AuditLog::count();
        foreach (['period_start', 'period_end'] as $field) {
            $data = ['warehouse_id' => $warehouse->id, 'shop_id' => $owner->shop->id,
                'client_token' => (string) Str::uuid(), 'reason' => 'Validate malformed reconciliation period',
                'period_start' => '2026-11-01', 'period_end' => '2026-11-20', $field => ['2026-11-01']];
            $this->actingAs($admin)->postJson(route('finance.reconciliations.store'), $data)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            try {
                app(ReconciliationService::class)->create($admin, $data);
                $this->fail('Invalid dates must be rejected before posting.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey($field, $error->errors());
            }
        }
        $this->assertSame(0, Reconciliation::count());
        $this->assertSame($logs, AuditLog::count());
    }

    public function test_customer_area_arrays_are_rejected_without_changing_account_or_profile(): void
    {
        $area = Area::firstOrFail();
        foreach ([[$area->id], ['id' => [$area->id]]] as $invalid) {
            $this->postJson(route('register.store'), [
                'account_type' => 'individual', 'name' => 'Invalid customer area', 'email' => 'invalid-area@test.local',
                'phone' => '0987000022', 'password' => 'TestPassword!2026', 'password_confirmation' => 'TestPassword!2026',
                'area_id' => $invalid, 'address' => 'Customer address',
            ])->assertUnprocessable()->assertJsonValidationErrors('area_id');
        }
        $this->assertDatabaseMissing('users', ['email' => 'invalid-area@test.local']);
        $owner = $this->shopOwner();
        $address = $owner->shop->addresses->sole();
        $before = $owner->shop->getAttributes();
        $logs = AuditLog::count();
        $this->actingAs($owner);
        foreach ([[$area->id], ['id' => [$area->id]]] as $invalid) {
            $this->putJson(route('shop.update'), ['name' => 'Must remain unchanged', 'address' => 'Customer address', 'area_id' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('area_id');
            $data = ['contact_name' => $owner->name, 'phone' => $owner->phone, 'address' => 'Customer address',
                'area_id' => $invalid, 'is_active' => 1, 'is_default' => 0];
            $this->postJson(route('pickups.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
            $this->putJson(route('pickups.update', $address), $data)->assertUnprocessable()->assertJsonValidationErrors('area_id');
        }
        $this->assertSame($before, $owner->shop->fresh()->getAttributes());
        $this->assertSame(1, $owner->shop->addresses()->count());
        $this->assertSame($address->getAttributes(), $address->fresh()->getAttributes());
        $this->assertSame($logs, AuditLog::count());
    }

    private function filteredPages(): array
    {
        return [route('users.index'), route('shops.index'), route('orders.index'), route('audit.index'),
            route('finance.index'), route('reports.index', 'operations'), route('reports.index', 'finance'), route('delivery.collections')];
    }
}
