<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketCommentsHistoryTest extends TestCase
{
    use RefreshDatabase;

    public static function commentAccess(): array
    {
        $cases = [];
        foreach (['employee', 'technician', 'admin'] as $role) {
            foreach (['open', 'in_progress', 'resolved'] as $status) {
                $cases[$role.' '.$status] = [$role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('commentAccess')]
    public function test_visible_users_can_comment_on_every_nonclosed_state_and_author_is_session_user(string $role, string $status): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create([
            'status' => $status, 'requester_id' => $role === 'employee' ? $actor->id : User::factory(),
            'technician_id' => $role === 'technician' ? $actor->id : null,
        ]);

        $response = $this->actingAs($actor, 'web')->postJson('/api/v1/tickets/'.$ticket->id.'/comments', ['content' => 'A useful diagnostic update.'])
            ->assertCreated()->assertJsonPath('data.ticket_id', $ticket->id)
            ->assertJsonPath('data.content', 'A useful diagnostic update.')->assertJsonPath('data.author.id', $actor->id);

        $this->assertEqualsCanonicalizing(['id', 'ticket_id', 'content', 'author', 'created_at', 'updated_at'], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($response->json('data.author')));
        $comment = TicketComment::query()->sole();
        $this->assertSame($actor->id, $comment->author_id);
        $event = TicketEvent::query()->sole();
        $this->assertSame('comment_added', $event->type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame(['before' => [], 'after' => ['comment_id' => $comment->id]], $event->metadata);
    }

    public function test_closed_ticket_rejects_comments_but_keeps_comments_and_events_readable(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id, 'status' => 'closed']);
        TicketComment::factory()->create(['ticket_id' => $ticket->id]);
        TicketEvent::factory()->create(['ticket_id' => $ticket->id, 'type' => 'closed']);

        $this->actingAs($owner, 'web')->postJson('/api/v1/tickets/'.$ticket->id.'/comments', ['content' => 'Too late.'])->assertUnprocessable();
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/comments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tickets/'.$ticket->id.'/events')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('ticket_comments', 1);
        $this->assertDatabaseCount('ticket_events', 1);
    }

    public static function invalidComments(): array
    {
        return [
            'empty request' => [[], 'content'],
            'empty string' => [['content' => ''], 'content'],
            'whitespace only' => [['content' => " \t\n "], 'content'],
            'null' => [['content' => null], 'content'],
            'array' => [['content' => ['text']], 'content'],
            'too long' => [['content' => str_repeat('a', 5001)], 'content'],
            'forged author' => [['content' => 'Text', 'author_id' => 1], 'author_id'],
            'forged ticket null' => [['content' => 'Text', 'ticket_id' => null], 'ticket_id'],
            'forged timestamp' => [['content' => 'Text', 'created_at' => null], 'created_at'],
            'forged identifier' => [['content' => 'Text', 'id' => 99], 'id'],
            'forged event' => [['content' => 'Text', 'events' => []], 'events'],
        ];
    }

    #[DataProvider('invalidComments')]
    public function test_comments_reject_invalid_content_and_forged_fields_without_writing(array $payload, string $field): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);

        $this->actingAs($owner, 'web')->postJson('/api/v1/tickets/'.$ticket->id.'/comments', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function validContentLengths(): array
    {
        return ['minimum' => [1], 'maximum' => [5000]];
    }

    #[DataProvider('validContentLengths')]
    public function test_comment_content_boundaries_are_accepted(int $length): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);

        $this->actingAs($owner, 'web')->postJson('/api/v1/tickets/'.$ticket->id.'/comments', ['content' => str_repeat('a', $length)])
            ->assertCreated()->assertJsonPath('data.content', str_repeat('a', $length));
    }

    public static function historyKinds(): array
    {
        return ['comments' => ['comments'], 'events' => ['events']];
    }

    #[DataProvider('historyKinds')]
    public function test_history_is_paginated_in_ascending_time_and_id_order_and_has_minimal_profiles(string $kind): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);
        $factory = $kind === 'comments' ? TicketComment::factory() : TicketEvent::factory();
        $timeField = $kind === 'comments' ? 'created_at' : 'occurred_at';
        $later = $factory->create(['ticket_id' => $ticket->id, $timeField => now()]);
        $earlier = $factory->count(17)->create(['ticket_id' => $ticket->id, $timeField => now()->subDay()]);
        $other = Ticket::factory()->create();
        $factory->create(['ticket_id' => $other->id, $timeField => now()->subDays(2)]);
        $expectedIds = [...$earlier->pluck('id')->all(), $later->id];

        $first = $this->actingAs($owner, 'web')->getJson('/api/v1/tickets/'.$ticket->id.'/'.$kind)
            ->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18)->assertJsonStructure(['links', 'meta']);
        $second = $this->getJson('/api/v1/tickets/'.$ticket->id.'/'.$kind.'?page=2')
            ->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame($expectedIds, [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]);
        $profile = $kind === 'comments' ? 'author' : 'actor';
        $keys = $kind === 'comments'
            ? ['id', 'ticket_id', 'content', 'author', 'created_at', 'updated_at']
            : ['id', 'type', 'actor', 'occurred_at', 'metadata'];
        foreach ($first->json('data') as $row) {
            $this->assertEqualsCanonicalizing($keys, array_keys($row));
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row[$profile]));
        }
    }

    public static function invalidHistoryQueries(): array
    {
        $cases = [];
        foreach (['comments', 'events'] as $kind) {
            foreach ([
                'zero page' => [['page' => 0], 'page'],
                'array page' => [['page' => [1]], 'page'],
                'zero size' => [['per_page' => 0], 'per_page'],
                'oversized' => [['per_page' => 101], 'per_page'],
                'unknown' => [['author_id' => 1], 'author_id'],
            ] as $name => [$query, $field]) {
                $cases[$kind.' '.$name] = [$kind, $query, $field];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidHistoryQueries')]
    public function test_history_validation_rejects_bad_pagination_and_unknown_filters(string $kind, array $query, string $field): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);

        $this->actingAs($owner, 'web')->getJson('/api/v1/tickets/'.$ticket->id.'/'.$kind.'?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[DataProvider('historyKinds')]
    public function test_history_accepts_pagination_boundaries_and_avoids_per_row_profile_queries(string $kind): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);
        $factory = $kind === 'comments' ? TicketComment::factory() : TicketEvent::factory();
        $factory->count(20)->create(['ticket_id' => $ticket->id]);
        $this->actingAs($owner, 'web');
        DB::enableQueryLog();

        try {
            DB::flushQueryLog();
            $this->getJson('/api/v1/tickets/'.$ticket->id.'/'.$kind.'?per_page=1')
                ->assertOk()->assertJsonPath('meta.per_page', 1);
            $smallCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->getJson('/api/v1/tickets/'.$ticket->id.'/'.$kind.'?per_page=100')
                ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 100);
            $largeCount = count(DB::getQueryLog());

            $this->assertLessThanOrEqual($smallCount + 2, $largeCount, 'History profiles must be eager loaded.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
