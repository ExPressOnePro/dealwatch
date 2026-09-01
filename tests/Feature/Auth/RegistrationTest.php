<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Регистрация включена флагом: config/dealwatch.php → registration_enabled. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Флаг читается при регистрации маршрутов — ставим его до создания приложения.
        putenv('DEALWATCH_ALLOW_REGISTRATION=true');
        $_ENV['DEALWATCH_ALLOW_REGISTRATION'] = 'true';
        $_SERVER['DEALWATCH_ALLOW_REGISTRATION'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('DEALWATCH_ALLOW_REGISTRATION');
        unset($_ENV['DEALWATCH_ALLOW_REGISTRATION'], $_SERVER['DEALWATCH_ALLOW_REGISTRATION']);

        parent::tearDown();
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
