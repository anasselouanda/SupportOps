<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_is_unauthorized(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_disabled_real_session_is_rejected_and_invalidated(): void
    {
        $user = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $sessionId = $this->app['session']->getId();
        $token = $this->app['session']->token();
        $user->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotSame($token, $this->app['session']->token());
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public static function visibleCounts(): array
    {
        return [
            'employee own tickets only' => ['employee', [17, 8, 4, 3, 2]],
            'technician own or assigned without duplicates' => ['technician', [24, 11, 6, 4, 3]],
            'admin global scope' => ['admin', [29, 13, 7, 5, 4]],
        ];
    }

    #[DataProvider('visibleCounts')]
    public function test_all_status_counts_use_full_role_scope_beyond_a_list_page(string $role, array $expectedCounts): void
    {
        $this->freezeTime();
        $viewer = User::factory()->create(['role' => $role]);
        $ownedIds = [];
        $assignedIds = [];
        $unrelatedIds = [];
        foreach (['open' => 8, 'in_progress' => 4, 'resolved' => 3, 'closed' => 2] as $status => $count) {
            $owned = Ticket::factory()->count($count)->create(['requester_id' => $viewer->id, 'status' => $status]);
            $ownedIds = [...$ownedIds, ...$owned->modelKeys()];
        }
        Ticket::query()->whereKey($ownedIds[0])->update(['technician_id' => $viewer->id]);
        foreach (['open' => 3, 'in_progress' => 2, 'resolved' => 1, 'closed' => 1] as $status => $count) {
            $assigned = Ticket::factory()->count($count)->create(['technician_id' => $viewer->id, 'status' => $status]);
            $assignedIds = [...$assignedIds, ...$assigned->modelKeys()];
        }
        foreach (['open' => 2, 'in_progress' => 1, 'resolved' => 1, 'closed' => 1] as $status => $count) {
            $unrelated = Ticket::factory()->count($count)->create(['status' => $status]);
            $unrelatedIds = [...$unrelatedIds, ...$unrelated->modelKeys()];
        }
        $expectedIds = match ($role) {
            'admin' => [...$ownedIds, ...$assignedIds, ...$unrelatedIds],
            'technician' => [...$ownedIds, ...$assignedIds],
            default => $ownedIds,
        };
        rsort($expectedIds);

        $response = $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonCount(5, 'data.recent_tickets');
        $stats = array_combine(['total', 'open', 'in_progress', 'resolved', 'closed'], $expectedCounts);

        $this->assertSame($stats, $response->json('data.stats'));
        $this->assertSame($stats['total'], array_sum(array_intersect_key($stats, array_flip(Ticket::STATUSES))));
        $this->assertSame(array_slice($expectedIds, 0, 5), array_column($response->json('data.recent_tickets'), 'id'));
        foreach ($response->json('data.stats') as $count) {
            $this->assertIsInt($count);
        }
    }

    public function test_empty_scope_returns_real_zero_counts_and_no_recent_tickets(): void
    {
        $viewer = User::factory()->create();
        Ticket::factory()->count(3)->create();

        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')->assertOk()->assertExactJson([
            'data' => [
                'stats' => ['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0],
                'recent_tickets' => [],
            ],
        ]);
    }

    public function test_recent_tickets_are_limited_and_sorted_by_creation_time_then_id_with_minimal_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $firstCreated = Ticket::factory()->create(['created_at' => now()->addDay(), 'technician_id' => User::factory()->technician()]);
        $older = Ticket::factory()->count(6)->create(['created_at' => now()->subDay()]);
        $expectedIds = [$firstCreated->id, ...array_slice(array_reverse($older->modelKeys()), 0, 4)];

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.stats.total', 7)->assertJsonCount(5, 'data.recent_tickets');

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(['stats', 'recent_tickets'], array_keys($response->json('data')));
        $this->assertSame($expectedIds, array_column($response->json('data.recent_tickets'), 'id'));
        foreach ($response->json('data.recent_tickets') as $index => $ticket) {
            $this->assertEqualsCanonicalizing([
                'id', 'reference', 'title', 'status', 'priority', 'category', 'technician', 'created_at',
            ], array_keys($ticket));
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($ticket['category']));
            if ($index === 0) {
                $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($ticket['technician']));
            } else {
                $this->assertNull($ticket['technician']);
            }
        }
    }

    public static function forbiddenParameters(): array
    {
        return [
            'user id' => ['user_id'], 'role' => ['role'], 'requester' => ['requester_id'],
            'status' => ['status'], 'page' => ['page'], 'page size' => ['per_page'], 'unknown' => ['unexpected'],
        ];
    }

    #[DataProvider('forbiddenParameters')]
    public function test_dashboard_rejects_every_parameter_even_empty(string $field): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/v1/dashboard?'.http_build_query([$field => '']))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_unknown_active_role_cannot_access_dashboard(): void
    {
        $viewer = User::factory()->create(['role' => 'unknown']);
        Ticket::factory()->create(['requester_id' => $viewer->id]);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_role_change_refreshes_existing_session_scope(): void
    {
        $viewer = User::factory()->admin()->create();
        Ticket::factory()->create();
        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total', 1);
        User::query()->whereKey($viewer->id)->update(['role' => 'employee']);

        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total', 0)->assertJsonCount(0, 'data.recent_tickets');
    }

    public function test_nonconforming_visible_status_returns_an_error_instead_of_false_counts(): void
    {
        $viewer = User::factory()->create();
        Ticket::factory()->create(['requester_id' => $viewer->id, 'status' => 'invalid_legacy_status']);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')->assertStatus(500);
    }

    public function test_nonconforming_invisible_status_does_not_affect_the_current_scope(): void
    {
        $viewer = User::factory()->create();
        Ticket::factory()->create(['status' => 'invalid_legacy_status']);
        Ticket::factory()->create(['requester_id' => $viewer->id]);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.stats.total', 1)->assertJsonPath('data.stats.open', 1);
    }

    public function test_dashboard_reflects_api_resolution_and_reopening_and_preserves_ticket_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'technician_id' => User::factory()->technician()]);
        $this->actingAs($admin, 'web');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.in_progress', 1);

        $this->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'resolved', 'resolution_note' => 'Restored the network connection.'])->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.resolved', 1)->assertJsonPath('data.stats.in_progress', 0);
        $this->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'open'])->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.open', 1)->assertJsonPath('data.stats.resolved', 0)
            ->assertJsonPath('data.recent_tickets.0.status', 'open');
        $this->getJson('/api/v1/tickets')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ticket->id);
        $this->getJson('/api/v1/tickets/'.$ticket->id)->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolution_note', null);
    }

    public function test_dashboard_queries_do_not_grow_with_each_recent_ticket_and_counts_are_aggregated(): void
    {
        $admin = User::factory()->admin()->create();
        Ticket::factory()->create(['technician_id' => User::factory()->technician()]);
        $this->actingAs($admin, 'web');
        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->getJson('/api/v1/dashboard')->assertOk();
            $smallCount = count(DB::getQueryLog());
            Ticket::factory()->count(25)->create(['technician_id' => User::factory()->technician()]);
            DB::flushQueryLog();
            $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total', 26)->assertJsonCount(5, 'data.recent_tickets');
            $queries = DB::getQueryLog();

            $this->assertLessThanOrEqual($smallCount + 1, count($queries), 'Recent category and technician relationships must be eager loaded.');
            $this->assertTrue(collect($queries)->contains(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')),
                'Statistics must be aggregated in SQL rather than loading the visible collection.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
