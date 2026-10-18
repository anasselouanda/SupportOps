<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [
            'categories' => ['GET', '/api/v1/categories'],
            'list' => ['GET', '/api/v1/tickets'],
            'create' => ['POST', '/api/v1/tickets'],
            'detail' => ['GET', '/api/v1/tickets/999999'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_anonymous_access_returns_401(string $method, string $endpoint): void
    {
        $this->json($method, $endpoint)->assertUnauthorized();
        $this->assertDatabaseCount('tickets', 0);
    }

    #[DataProvider('endpoints')]
    public function test_a_disabled_real_session_is_rejected_and_invalidated(string $method, string $endpoint): void
    {
        $user = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $sessionId = $this->app['session']->getId();
        $token = $this->app['session']->token();
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->json($method, $endpoint)->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotSame($token, $this->app['session']->token());
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseCount('tickets', 0);
    }

    public static function roles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician'], 'admin' => ['admin']];
    }

    #[DataProvider('roles')]
    public function test_active_categories_are_sorted_and_expose_only_selector_fields(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $last = Category::factory()->create(['name' => 'Software']);
        $first = Category::factory()->create(['name' => 'Hardware']);
        Category::factory()->inactive()->create(['name' => 'Archived']);

        $this->actingAs($user, 'web')->getJson('/api/v1/categories')->assertOk()->assertExactJson([
            'data' => [['id' => $first->id, 'name' => 'Hardware'], ['id' => $last->id, 'name' => 'Software']],
        ]);
    }

    public static function detailMatrix(): array
    {
        return [
            'employee own' => ['employee', true, false, 200],
            'employee assigned only' => ['employee', false, true, 404],
            'employee unrelated' => ['employee', false, false, 404],
            'technician own' => ['technician', true, false, 200],
            'technician assigned' => ['technician', false, true, 200],
            'technician unrelated' => ['technician', false, false, 404],
            'admin unrelated' => ['admin', false, false, 200],
            'unknown role unrelated' => ['unknown', false, false, 404],
        ];
    }

    #[DataProvider('detailMatrix')]
    public function test_details_obey_role_and_ownership_scope(string $role, bool $owns, bool $assigned, int $status): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create([
            'requester_id' => $owns ? $viewer->id : User::factory(),
            'technician_id' => $assigned ? $viewer->id : User::factory()->technician(),
        ]);

        $response = $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets/'.$ticket->id)->assertStatus($status);

        if ($status === 200) {
            $response->assertJsonPath('data.id', $ticket->id)
                ->assertJsonPath('data.technician.id', $ticket->technician_id);
            foreach (['requester', 'technician', 'category'] as $relation) {
                $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($response->json('data.'.$relation)));
            }
        }
    }

    public function test_missing_detail_returns_404_for_an_active_administrator(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/v1/tickets/999999')->assertNotFound();
    }

    public function test_role_changes_are_applied_even_when_guard_instance_is_stale(): void
    {
        $viewer = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets/'.$ticket->id)->assertOk();
        User::query()->whereKey($viewer->id)->update(['role' => 'employee']);

        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertNotFound();
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->assertAuthenticated('web');
    }

    public function test_existing_tickets_keep_their_category_when_it_becomes_inactive(): void
    {
        $requester = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $requester->id]);
        $ticket->category->update(['is_active' => false]);

        $this->actingAs($requester, 'web')->getJson('/api/v1/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('data.category.id', $ticket->category_id);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
    }
}
