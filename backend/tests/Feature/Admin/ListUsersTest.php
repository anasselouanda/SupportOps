<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    }

    public static function unprivilegedRoles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician']];
    }

    #[DataProvider('unprivilegedRoles')]
    public function test_non_admin_request_returns_403(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user, 'web')->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_receives_paginated_public_user_fields(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(15)->create();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/users');

        $response->assertOk()->assertJsonPath('per_page', 15)->assertJsonPath('total', 16)->assertJsonCount(15, 'data');
        foreach ($response->json('data') as $user) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'role', 'is_active'], array_keys($user));
            $this->assertIsBool($user['is_active']);
        }
    }

    public function test_admin_can_choose_page_size_and_second_page(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();

        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/users?per_page=2&page=2')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonPath('per_page', 2)->assertJsonCount(2, 'data');
    }

    public static function invalidPageSizes(): array
    {
        return ['zero' => ['0'], 'too large' => ['101'], 'not numeric' => ['invalid']];
    }

    public function test_maximum_allowed_page_size_is_100(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/users?per_page=100')
            ->assertOk()->assertJsonPath('per_page', 100);
    }

    #[DataProvider('invalidPageSizes')]
    public function test_invalid_page_size_returns_422(string $pageSize): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/users?per_page='.$pageSize)
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }
}
