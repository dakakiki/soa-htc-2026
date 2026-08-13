<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_log_in_with_valid_credentials(): void
    {
        $this->seed();

        // Emulate a first-party SPA request so Sanctum starts the session
        // (in the browser axios sends this Origin automatically).
        $response = $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', [
                'email' => 'admin@soahtc.test',
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'admin@soahtc.test')
            ->assertJsonPath('data.is_admin', true);
        $this->assertAuthenticated();
    }

    public function test_login_response_exposes_roles_and_permissions(): void
    {
        $this->seed();

        $response = $this->withHeader('Origin', config('app.url'))
            ->postJson('/api/auth/login', [
                'email' => 'admin@soahtc.test',
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.roles.0', 'admin')
            ->assertJsonPath(
                'data.permissions',
                fn (array $p): bool => in_array('schools.manage', $p, true)
                    && in_array('schools.view.all', $p, true)
            );
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->seed();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@soahtc.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_authenticated_user_endpoint_returns_current_user(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@soahtc.test')->firstOrFail();

        $this->actingAs($admin)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@soahtc.test');
    }
}
