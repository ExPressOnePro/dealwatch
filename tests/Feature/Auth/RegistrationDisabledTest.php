<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лента сделок общая для всех аккаунтов, поэтому по умолчанию посторонний
 * не должен заводить себе доступ сам.
 */
class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_closed_by_default(): void
    {
        $this->assertFalse(config('dealwatch.registration_enabled'));

        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }
}
