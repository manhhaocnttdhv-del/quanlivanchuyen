<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function draftData(array $overrides = []): array
    {
        return array_merge([
            'sender_name' => 'Người gửi mẫu', 'sender_phone' => '0900000001',
            'sender_address' => '10 Đường mẫu, phường mẫu', 'origin' => 'HN',
            'recipient_name' => 'Người nhận mẫu', 'recipient_phone' => '0900006789',
            'recipient_address' => '20 Đường mẫu, phường mẫu', 'destination' => 'HCM',
            'goods' => 'Hai áo sơ mi', 'weight' => 501, 'service' => 'standard',
            'cod' => 500000, 'fee_payer' => 'sender', 'note' => 'Dữ liệu kiểm thử',
        ], $overrides);
    }

    private function storedDraft(array $overrides = []): array
    {
        return array_merge($this->draftData(), [
            'id' => (string) Str::uuid(), 'code' => 'NHAP-TEST123456',
            'updated_at' => '2026-10-28 10:00:00',
        ], $overrides);
    }

    public function test_public_pages_render_without_authentication_or_business_database(): void
    {
        foreach (['/', '/gui-hang', '/don-hang'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_fee_is_calculated_on_server_and_ignores_client_price(): void
    {
        $this->postJson('/uoc-tinh-cuoc', array_merge($this->draftData(), ['total' => 1]))
            ->assertOk()->assertJsonPath('total', 45000);
    }

    public function test_quote_rejects_unknown_provinces_fractional_or_out_of_range_weight(): void
    {
        foreach ([0, -1, 20001, '500.5'] as $weight) {
            $this->postJson('/uoc-tinh-cuoc', $this->draftData(['weight' => $weight]))
                ->assertUnprocessable()->assertJsonValidationErrors('weight');
        }

        $this->postJson('/uoc-tinh-cuoc', $this->draftData(['origin' => 'UNKNOWN', 'service' => 'free']))
            ->assertUnprocessable()->assertJsonValidationErrors(['origin', 'service']);
    }

    public function test_non_javascript_quote_redirects_back_with_result(): void
    {
        $this->post('/uoc-tinh-cuoc', $this->draftData())->assertRedirect('/?tool=estimate#cong-cu')
            ->assertSessionHas('quote.total', 45000);
    }

    public function test_saving_and_editing_draft_retains_code_and_only_validated_fields(): void
    {
        $response = $this->post('/don-hang', array_merge($this->draftData(), ['status' => 'delivered', 'total' => 1]));
        $draft = array_values(session('customer_drafts'))[0];
        $response->assertRedirect(route('drafts.edit', $draft['id']));
        $this->assertStringStartsWith('NHAP-', $draft['code']);
        $this->assertArrayNotHasKey('status', $draft);
        $this->assertArrayNotHasKey('total', $draft);

        $this->get(route('drafts.edit', $draft['id']))->assertOk();
        $this->put(route('drafts.update', $draft['id']), $this->draftData(['goods' => 'Áo đã sửa']))->assertRedirect();
        $updated = session('customer_drafts')[$draft['id']];
        $this->assertSame($draft['code'], $updated['code']);
        $this->assertSame('Áo đã sửa', $updated['goods']);
    }

    public function test_draft_validates_phone_cod_and_required_fields_without_losing_old_input(): void
    {
        $this->from('/gui-hang')->post('/don-hang', $this->draftData([
            'sender_phone' => '123', 'recipient_phone' => 'abcdef', 'cod' => -1, 'goods' => '',
        ]))->assertRedirect('/gui-hang')->assertSessionHasErrors(['sender_phone', 'recipient_phone', 'cod', 'goods'])
            ->assertSessionHasInput('recipient_name', 'Người nhận mẫu');
        $this->assertEmpty(session('customer_drafts', []));

        $this->postJson('/don-hang', $this->draftData(['cod' => 20000001]))
            ->assertUnprocessable()->assertJsonValidationErrors('cod');
    }

    public function test_other_session_cannot_read_update_delete_or_track_a_draft(): void
    {
        $foreign = $this->storedDraft();
        $this->withSession(['customer_drafts' => []]);
        $this->get(route('drafts.edit', $foreign['id']))->assertNotFound();
        $this->put(route('drafts.update', $foreign['id']), $this->draftData())->assertNotFound();
        $this->delete(route('drafts.destroy', $foreign['id']))->assertNotFound();
        $this->postJson('/tra-cuu', ['tracking_code' => $foreign['code'], 'phone_suffix' => '6789'])
            ->assertOk()->assertJsonPath('found', false);
    }

    public function test_delete_removes_only_requested_draft(): void
    {
        $first = $this->storedDraft();
        $second = $this->storedDraft(['code' => 'NHAP-SECOND']);
        $this->withSession(['customer_drafts' => [$first['id'] => $first, $second['id'] => $second]])
            ->delete(route('drafts.destroy', $first['id']))->assertRedirect(route('drafts.index'));
        $this->assertArrayNotHasKey($first['id'], session('customer_drafts'));
        $this->assertArrayHasKey($second['id'], session('customer_drafts'));
    }

    public function test_draft_limit_prevents_session_growth_but_allows_edit(): void
    {
        $drafts = [];
        for ($i = 0; $i < 20; $i++) {
            $draft = $this->storedDraft();
            $drafts[$draft['id']] = $draft;
        }
        $this->withSession(['customer_drafts' => $drafts])->postJson('/don-hang', $this->draftData())
            ->assertUnprocessable()->assertJsonValidationErrors('goods');
        $this->assertCount(20, session('customer_drafts'));
        $this->put(route('drafts.update', $draft['id']), $this->draftData())->assertRedirect();
    }

    public function test_tracking_requires_matching_verification_and_never_returns_private_data(): void
    {
        $draft = $this->storedDraft();
        $this->withSession(['customer_drafts' => [$draft['id'] => $draft]]);
        $wrongPhone = $this->postJson('/tra-cuu', ['tracking_code' => $draft['code'], 'phone_suffix' => '0000'])->assertOk()->json();
        $unknownCode = $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertOk()->json();
        $this->assertSame($unknownCode, $wrongPhone);

        $result = $this->postJson('/tra-cuu', ['tracking_code' => strtolower($draft['code']), 'phone_suffix' => '6789'])
            ->assertOk()->assertJsonPath('found', true)->json();
        $this->assertSame(['found', 'code', 'message'], array_keys($result));
        foreach (['recipient_name', 'recipient_phone', 'recipient_address', 'cod'] as $field) {
            $this->assertStringNotContainsString((string) $draft[$field], json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_tracking_rejects_malformed_input_and_limits_attempts(): void
    {
        $this->postJson('/tra-cuu', ['tracking_code' => ['array'], 'phone_suffix' => '12'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tracking_code', 'phone_suffix']);
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertOk();
        }
        $this->postJson('/tra-cuu', ['tracking_code' => 'UNKNOWN', 'phone_suffix' => '6789'])->assertStatus(429);
    }

    public function test_invalid_array_input_can_render_again_after_validation_redirect(): void
    {
        $this->from('/gui-hang')->post('/don-hang', $this->draftData(['goods' => ['invalid']]))
            ->assertRedirect('/gui-hang')->assertSessionHasErrors('goods');
        $this->get('/gui-hang')->assertOk();

        $this->from('/')->post('/tra-cuu', ['tracking_code' => ['invalid'], 'phone_suffix' => '6789'])
            ->assertRedirect('/')->assertSessionHasErrors('tracking_code');
        $this->get('/')->assertOk();
    }

    public function test_search_scopes_results_and_rejects_array_input(): void
    {
        $draft = $this->storedDraft();
        $this->withSession(['customer_drafts' => [$draft['id'] => $draft]])
            ->get('/don-hang?q=6789')->assertOk()->assertViewHas('drafts', fn ($drafts) => $drafts->count() === 1);
        $this->get('/don-hang?q=no-match')->assertOk()->assertViewHas('drafts', fn ($drafts) => $drafts->isEmpty());
        $this->getJson('/don-hang?q[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
