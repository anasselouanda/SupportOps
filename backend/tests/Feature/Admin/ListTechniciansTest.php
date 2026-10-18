<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListTechniciansTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_is_unauthorized(): void
    {
        $this->getJson('/api/v1/admin/technicians')->assertUnauthorized();
    }

    public static function nonAdminRoles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_list_technicians(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]), 'web')
            ->getJson('/api/v1/admin/technicians')->assertForbidden();
    }

    public function test_disabled_real_session_is_rejected_and_invalidated(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $sessionId = $this->app['session']->getId();
        $token = $this->app['session']->token();
        $admin->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/technicians')->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotSame($token, $this->app['session']->token());
    }

    public function test_only_active_technicians_are_returned_sorted_by_name_then_id_with_minimal_fields(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'A hidden administrator']);
        User::factory()->create(['name' => 'A hidden employee']);
        User::factory()->technician()->inactive()->create(['name' => 'A hidden inactive technician']);
        $last = User::factory()->technician()->create(['name' => 'Zohra']);
        $first = User::factory()->technician()->create(['name' => 'Amine']);
        $second = User::factory()->technician()->create(['name' => 'Amine']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/technicians')
            ->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertSame([
            ['id' => $first->id, 'name' => 'Amine'],
            ['id' => $second->id, 'name' => 'Amine'],
            ['id' => $last->id, 'name' => 'Zohra'],
        ], $response->json('data'));
    }

    public function test_pagination_can_retrieve_technicians_beyond_the_first_hundred(): void
    {
        $admin = User::factory()->admin()->create();
        $technicians = User::factory()->technician()->count(101)->sequence(fn ($sequence) => [
            'name' => sprintf('Technician %03d', $sequence->index),
        ])->create();

        $firstPage = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/technicians?per_page=100')
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 100);
        $secondPage = $this->getJson('/api/v1/admin/technicians?per_page=100&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);

        $this->assertSame($technicians->pluck('id')->all(), [
            ...array_column($firstPage->json('data'), 'id'), ...array_column($secondPage->json('data'), 'id'),
        ]);
    }

    public static function invalidQueries(): array
    {
        return [
            'zero page' => [['page' => 0], 'page'],
            'array page' => [['page' => [1]], 'page'],
            'zero size' => [['per_page' => 0], 'per_page'],
            'oversized size' => [['per_page' => 101], 'per_page'],
            'noninteger size' => [['per_page' => 'abc'], 'per_page'],
            'unknown filter' => [['role' => 'admin'], 'role'],
            'unknown field' => [['search' => 'Amine'], 'search'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_pagination_and_unknown_fields_return_422(array $query, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/v1/admin/technicians?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
