<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_update_returns_401_without_writing(): void
    {
        $target = User::factory()->create();

        $this->patchJson('/api/v1/admin/users/'.$target->id, ['role' => 'admin'])->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'employee']);
    }

    public function test_technician_update_returns_403_without_writing(): void
    {
        $technician = User::factory()->technician()->create();
        $target = User::factory()->create();

        $this->actingAs($technician, 'web')->patchJson('/api/v1/admin/users/'.$target->id, ['role' => 'admin'])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'employee']);
    }

    public function test_admin_updates_allowed_fields_and_preserves_password(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $originalPassword = $target->getRawOriginal('password');

        $response = $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Updated', 'email' => 'updated@example.com', 'role' => 'technician', 'is_active' => false,
        ]);

        $response->assertOk()->assertJsonPath('user.name', 'Updated')->assertJsonPath('user.email', 'updated@example.com')
            ->assertJsonPath('user.role', 'technician')->assertJsonPath('user.is_active', false)
            ->assertJsonStructure(['message', 'user']);
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role', 'is_active'], array_keys($response->json('user')));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Updated', 'email' => 'updated@example.com', 'role' => 'technician', 'is_active' => false]);
        $this->assertSame($originalPassword, $target->fresh()->getRawOriginal('password'));
    }

    public function test_partial_update_preserves_omitted_fields_and_accepts_own_email(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->technician()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, ['email' => $target->email, 'name' => 'Only name'])
            ->assertOk()->assertJsonPath('user.role', 'technician')->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Only name', 'email' => $target->email, 'role' => 'technician', 'is_active' => true]);
    }

    public function test_admin_can_reactivate_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->inactive()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, ['is_active' => true])
            ->assertOk()->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public static function invalidUpdates(): array
    {
        return [
            'invalid name' => [['name' => ''], 'name'],
            'invalid email' => [['email' => 'invalid'], 'email'],
            'invalid role' => [['role' => 'owner'], 'role'],
            'invalid active state' => [['is_active' => 'active'], 'is_active'],
            'password injection' => [['password' => 'NewPassword123!'], 'password'],
            'unknown attribute' => [['email_verified_at' => '2026-10-05'], 'email_verified_at'],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_update_returns_422_without_writing(array $payload, string $field): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $original = $target->fresh()->getAttributes();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($original, $target->fresh()->getAttributes());
    }

    public function test_duplicate_email_returns_422_without_writing(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, ['email' => $admin->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => $target->email]);
    }

    public function test_unknown_user_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/999999', ['name' => 'Unknown'])->assertNotFound();
    }

    public static function lastAdminChanges(): array
    {
        return ['demotion' => [['role' => 'employee'], 'role'], 'deactivation' => [['is_active' => false], 'is_active']];
    }

    #[DataProvider('lastAdminChanges')]
    public function test_last_active_admin_change_returns_422(array $payload, string $field): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->inactive()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$admin->id, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_admin_can_demote_another_admin_when_one_remains_active(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$target->id, ['role' => 'employee', 'is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'employee', 'is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_second_admin_removal_is_refused_after_the_first_succeeds(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web')->patchJson('/api/v1/admin/users/'.$otherAdmin->id, ['is_active' => false])->assertOk();

        $this->patchJson('/api/v1/admin/users/'.$admin->id, ['role' => 'employee'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'role' => 'admin', 'is_active' => false]);
    }
}
