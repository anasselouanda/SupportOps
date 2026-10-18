<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_login_returns_422_and_keeps_guest_session(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'SecurePassword123!']);

        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public static function protectedEndpoints(): array
    {
        return ['profile' => ['GET', '/api/user'], 'admin list' => ['GET', '/api/v1/admin/users'],
            'admin create' => ['POST', '/api/v1/admin/users'], 'logout' => ['POST', '/api/logout']];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_disabled_session_returns_403_and_is_invalidated(string $method, string $endpoint): void
    {
        $user = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $sessionId = $this->app['session']->getId();
        $csrfToken = $this->app['session']->token();
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->json($method, $endpoint)->assertForbidden()->assertExactJson([
            'message' => 'Votre compte a été désactivé. Contactez un administrateur.', 'code' => 'account_disabled',
        ]);

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotSame($csrfToken, $this->app['session']->token());
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_role_change_takes_effect_in_an_existing_session(): void
    {
        $user = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $user->update(['role' => 'employee']);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.role', 'employee');
    }

    public function test_disabled_user_is_detected_even_when_the_guard_has_a_stale_instance(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'web');
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->getJson('/api/user')->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
    }

    public function test_demoted_admin_is_refused_with_a_stale_guard_and_keeps_a_session(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'web');
        User::query()->whereKey($user->id)->update(['role' => 'employee']);

        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.role', 'employee');
    }
}
