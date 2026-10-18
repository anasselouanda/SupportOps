<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListTicketsTest extends TestCase
{
    use RefreshDatabase;

    public static function visibility(): array
    {
        return ['employee' => ['employee', 1], 'technician' => ['technician', 2], 'admin' => ['admin', 3]];
    }

    #[DataProvider('visibility')]
    public function test_listing_obeys_role_scope(string $role, int $expectedTotal): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $own = Ticket::factory()->create(['requester_id' => $viewer->id]);
        $assigned = Ticket::factory()->create(['technician_id' => $viewer->id]);
        $other = Ticket::factory()->create();

        $response = $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets')->assertOk()
            ->assertJsonPath('meta.total', $expectedTotal)->assertJsonCount($expectedTotal, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
        $expected = [$own->id];
        if ($role !== 'employee') {
            $expected[] = $assigned->id;
        }
        if ($role === 'admin') {
            $expected[] = $other->id;
        }
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'id'));
    }

    public function test_search_matches_title_or_reference_without_leaking_other_requesters_tickets(): void
    {
        $viewer = User::factory()->create();
        $titleMatch = Ticket::factory()->create(['requester_id' => $viewer->id, 'title' => 'Printer needs service']);
        $referenceMatch = Ticket::factory()->create(['requester_id' => $viewer->id, 'reference' => 'TKT-PRINTER-001', 'title' => 'Network issue']);
        Ticket::factory()->create(['title' => 'Printer private issue', 'reference' => 'TKT-PRINTER-PRIVATE']);
        Ticket::factory()->create(['requester_id' => $viewer->id, 'title' => 'Network only']);

        $response = $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets?search=Printer')->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertEqualsCanonicalizing([$titleMatch->id, $referenceMatch->id], array_column($response->json('data'), 'id'));
    }

    public static function literalSearches(): array
    {
        return ['percent' => ['%'], 'underscore' => ['_'], 'escape character' => ['!']];
    }

    #[DataProvider('literalSearches')]
    public function test_search_treats_sql_wildcards_and_escape_character_as_literal_text(string $search): void
    {
        $viewer = User::factory()->create();
        $match = Ticket::factory()->create(['requester_id' => $viewer->id, 'title' => 'Printer '.$search.' problem']);
        Ticket::factory()->create(['requester_id' => $viewer->id, 'title' => 'Printer ordinary problem']);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    }

    public function test_filters_combine_with_search_and_visibility(): void
    {
        $viewer = User::factory()->technician()->create();
        $match = Ticket::factory()->create(['technician_id' => $viewer->id, 'title' => 'Printer match', 'status' => 'in_progress', 'priority' => 'high']);
        Ticket::factory()->create(['technician_id' => $viewer->id, 'title' => 'Printer wrong status', 'status' => 'open', 'priority' => 'high']);
        Ticket::factory()->create(['requester_id' => $viewer->id, 'title' => 'Printer wrong priority', 'status' => 'in_progress', 'priority' => 'low']);
        Ticket::factory()->create(['title' => 'Printer unrelated', 'status' => 'in_progress', 'priority' => 'high']);

        $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets?search=Printer&status=in_progress&priority=high')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    }

    public static function invalidQueries(): array
    {
        return [
            'unknown status' => [['status' => 'deleted'], 'status'],
            'array status' => [['status' => ['open']], 'status'],
            'unknown priority' => [['priority' => 'critical'], 'priority'],
            'array priority' => [['priority' => ['high']], 'priority'],
            'long search' => [['search' => str_repeat('a', 256)], 'search'],
            'array search' => [['search' => ['private']], 'search'],
            'zero page' => [['page' => 0], 'page'],
            'negative page' => [['page' => -1], 'page'],
            'noninteger page' => [['page' => 'abc'], 'page'],
            'zero page size' => [['per_page' => 0], 'per_page'],
            'oversized page' => [['per_page' => 101], 'per_page'],
            'array page size' => [['per_page' => [10]], 'per_page'],
            'forged requester filter' => [['requester_id' => 1], 'requester_id'],
            'unknown parameter' => [['sort' => 'id'], 'sort'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_and_pagination_are_rejected(array $query, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/tickets?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_default_pagination_is_fifteen_and_order_is_stable_for_equal_timestamps(): void
    {
        $viewer = User::factory()->create();
        $tickets = Ticket::factory()->count(18)->create([
            'requester_id' => $viewer->id, 'created_at' => now()->subDay(),
        ]);
        $newest = Ticket::factory()->create(['requester_id' => $viewer->id, 'created_at' => now()]);
        $expectedIds = [$newest->id, ...$tickets->pluck('id')->reverse()->values()->all()];

        $firstPage = $this->actingAs($viewer, 'web')->getJson('/api/v1/tickets')->assertOk()
            ->assertJsonCount(15, 'data')->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 19)->assertJsonPath('meta.last_page', 2);
        $secondPage = $this->getJson('/api/v1/tickets?page=2')->assertOk()
            ->assertJsonCount(4, 'data')->assertJsonPath('meta.current_page', 2);

        $this->assertSame($expectedIds, [
            ...array_column($firstPage->json('data'), 'id'),
            ...array_column($secondPage->json('data'), 'id'),
        ]);
    }

    public static function pageSizes(): array
    {
        return ['minimum' => [1], 'maximum' => [100]];
    }

    #[DataProvider('pageSizes')]
    public function test_page_size_boundaries_are_accepted(int $perPage): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/tickets?per_page='.$perPage)
            ->assertOk()->assertJsonPath('meta.per_page', $perPage);
    }

    public function test_list_profiles_are_minimal_and_relationship_queries_do_not_grow_per_ticket(): void
    {
        $viewer = User::factory()->admin()->create();
        Ticket::factory()->count(20)->create(['technician_id' => User::factory()->technician()]);
        $this->actingAs($viewer, 'web');
        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->getJson('/api/v1/tickets?per_page=1')->assertOk();
            $singleCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $response = $this->getJson('/api/v1/tickets?per_page=25')->assertOk()->assertJsonCount(20, 'data');
            $manyCount = count(DB::getQueryLog());

            $this->assertLessThanOrEqual($singleCount + 2, $manyCount, 'Related profile queries must be eager loaded.');
            foreach ($response->json('data') as $ticket) {
                foreach (['requester', 'technician', 'category'] as $relation) {
                    $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($ticket[$relation]));
                }
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
