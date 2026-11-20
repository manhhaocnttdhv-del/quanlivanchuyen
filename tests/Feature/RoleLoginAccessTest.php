<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;

class RoleLoginAccessTest extends DatabaseTestCase
{
    public function test_all_four_roles_can_login_by_email_and_phone_with_correct_workspace_and_admin_permissions(): void
    {
        foreach ($this->workspaces() as $role => $destination) {
            $user = $role === 'shop' ? $this->shopOwner() : $this->person($role);
            foreach ([$user->email, $user->phone] as $identity) {
                $this->post(route('login.store'), ['identity' => $identity, 'password' => 'TestPassword!2026'])
                    ->assertRedirect(route($destination));
                $this->assertAuthenticatedAs($user);
                $this->get(route('login'))->assertRedirect(route($destination));
                foreach (['users.index', 'catalog.index', 'fees.index', 'audit.index'] as $name) {
                    $response = $this->get(route($name));
                    $role === 'admin' ? $response->assertOk() : $response->assertForbidden();
                }
                $this->get('/admin')->assertRedirect(route($destination));
                $this->post(route('logout'))->assertRedirect(route('home'));
                $this->assertGuest();
            }
        }
    }

    public function test_admin_entry_prompts_login_then_returns_to_the_users_workspace(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $user = $this->person();
        $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'TestPassword!2026'])
            ->assertRedirect(url('/admin'));
        $this->get('/admin')->assertRedirect(route('dashboard'));
    }

    public function test_unavailable_admin_intended_pages_fall_back_to_role_workspace(): void
    {
        foreach (['operations', 'shipper', 'shop'] as $role) {
            $user = $role === 'shop' ? $this->shopOwner() : $this->person($role);
            foreach (['users.index', 'catalog.index', 'fees.index', 'audit.index'] as $name) {
                $this->get(route($name))->assertRedirect(route('login'));
                $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'TestPassword!2026'])
                    ->assertRedirect(route($this->workspaces()[$role]));
                $this->get(route($name))->assertForbidden();
                $this->post(route('logout'));
            }
        }
    }

    public function test_authorized_admin_and_operations_intended_pages_keep_their_query(): void
    {
        foreach (['admin' => route('users.index', ['q' => 'demo']), 'operations' => route('shops.index', ['status' => 'pending'])] as $role => $url) {
            $user = $this->person($role);
            $this->get($url)->assertRedirect(route('login'));
            $this->post(route('login.store'), ['identity' => $user->email, 'password' => 'TestPassword!2026'])->assertRedirect($url);
            $this->get($url)->assertOk();
            $this->post(route('logout'));
        }
    }

    private function workspaces(): array
    {
        return ['admin' => 'dashboard', 'operations' => 'logistics.index', 'shipper' => 'delivery.mine', 'shop' => 'orders.index'];
    }
}
