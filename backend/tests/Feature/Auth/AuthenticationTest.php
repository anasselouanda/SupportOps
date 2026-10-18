<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://127.0.0.1:5173';

    private const PASSWORD = 'SecurePassword123!';

    public function test_registration_is_closed_and_returns_404_without_creating_a_user(): void
    {
        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/register', [
                'name' => 'Uninvited Admin',
                'email' => 'uninvited@example.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => 'admin',
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_login_succeeds_and_returns_only_public_user_fields(): void
    {
        $user = $this->createUser();

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Connexion réussie.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'employee',
                    'is_active' => true,
                ],
            ]);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_rejects_bad_credentials_with_the_same_generic_email_error(): void
    {
        $user = $this->createUser();

        $wrongPassword = $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'WrongPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $unknownEmail = $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'email' => 'unknown@example.com',
                'password' => 'WrongPassword123!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->withHeader('Origin', self::FRONTEND_ORIGIN);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'unknown@example.com',
                'password' => 'WrongPassword123!',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'unknown@example.com',
            'password' => 'WrongPassword123!',
        ])->assertStatus(429);
    }

    public function test_api_user_rejects_an_unauthenticated_request(): void
    {
        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_api_user_returns_only_public_fields_after_login(): void
    {
        $user = $this->createUser();
        $this->loginUser($user);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'employee',
                    'is_active' => true,
                ],
            ]);
    }

    public function test_logout_succeeds(): void
    {
        $user = $this->createUser();
        $this->loginUser($user);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Déconnexion réussie.']);
    }

    public function test_logout_rejects_an_unauthenticated_request(): void
    {
        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/logout')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_user_is_a_guest_after_logout(): void
    {
        $user = $this->createUser();
        $this->loginUser($user);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertGuest('web');
    }

    public function test_api_user_is_rejected_after_logout(): void
    {
        $user = $this->createUser();
        $this->loginUser($user);

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/logout')
            ->assertOk();

        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_health_route_remains_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'message' => 'API SupportOps disponible',
            ]);
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'name' => 'Anass',
            'email' => 'anass@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    private function loginUser(User $user): void
    {
        $this->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])
            ->assertOk();
    }
}
