<?php

namespace Tests\Feature;

use Tests\DatabaseTestCase;

class DatabaseSessionTest extends DatabaseTestCase
{
    public function test_login_page_stores_a_guest_session_in_the_database(): void
    {
        config(['session.driver' => 'database']);

        $this->get('/dang-nhap')->assertOk();

        $this->assertDatabaseHas('sessions', [
            'id' => app('session.store')->getId(),
            'user_id' => null,
        ]);
    }

    public function test_database_session_restores_login_and_logout_removes_it(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->person();

        $this->post('/dang-nhap', [
            'identity' => $user->email,
            'password' => 'TestPassword!2026',
        ])->assertRedirect(route('dashboard'));

        $sessionId = app('session.store')->getId();
        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
        ]);

        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $this->withCookie(config('session.cookie'), $sessionId)
            ->get('/tong-quan')->assertOk();

        $this->assertAuthenticatedAs($user);

        $this->post('/dang-xuat')->assertRedirect(route('home'));

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertGuest();
    }
}
