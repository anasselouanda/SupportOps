<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreUserTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SecurePassword123!';

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New technician', 'email' => 'new@example.com', 'role' => 'technician',
            'is_active' => true, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    public function test_anonymous_create_returns_401_without_writing(): void
    {
        $this->postJson('/api/v1/admin/users', $this->payload())->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_employee_create_returns_403_without_writing(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee, 'web')->postJson('/api/v1/admin/users', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public static function allowedRoles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician'], 'admin' => ['admin']];
    }

    #[DataProvider('allowedRoles')]
    public function test_admin_creates_user_with_hashed_password_and_public_fields(string $role): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', $this->payload(['role' => $role]));

        $response->assertCreated()->assertJsonPath('user.name', 'New technician')
            ->assertJsonPath('user.email', 'new@example.com')->assertJsonPath('user.role', $role)
            ->assertJsonPath('user.is_active', true)->assertJsonStructure(['message', 'user']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role', 'is_active'], array_keys($response->json('user')));
        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => $role, 'is_active' => true]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->getRawOriginal('password')));
        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'));
    }

    public function test_admin_can_create_an_inactive_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', $this->payload(['is_active' => false]))
            ->assertCreated()->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'is_active' => false]);
    }

    public function test_active_state_defaults_to_true_when_omitted(): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        unset($payload['is_active']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', $payload)
            ->assertCreated()->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'is_active' => true]);
    }

    public static function invalidPayloads(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'invalid email' => [['email' => 'bad-email'], 'email'],
            'unknown role' => [['role' => 'superadmin'], 'role'],
            'invalid active state' => [['is_active' => 'yes'], 'is_active'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'unconfirmed password' => [['password_confirmation' => 'different'], 'password'],
            'long password' => [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)], 'password'],
            'unexpected attribute' => [['remember_token' => 'attacker-controlled'], 'remember_token'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_create_returns_422_without_writing(array $overrides, string $field): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', $this->payload($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_missing_required_fields_return_422_without_writing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role', 'password']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_email_returns_422_without_writing(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'new@example.com']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/users', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }
}
