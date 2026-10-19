<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StoreTicketTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'category_id' => Category::factory()->create()->id,
            'title' => 'Printer unavailable',
            'description' => 'The printer no longer responds to print jobs.',
        ], $overrides);
    }

    public static function roles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician'], 'admin' => ['admin']];
    }

    #[DataProvider('roles')]
    public function test_active_roles_create_their_own_unassigned_ticket_and_initial_event(string $role): void
    {
        $requester = User::factory()->create(['role' => $role]);
        $payload = $this->payload();

        $response = $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $payload);

        $response->assertCreated()->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath('data.category.id', $payload['category_id'])
            ->assertJsonPath('data.priority', 'normal')->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.technician', null)->assertJsonPath('data.resolved_at', null)
            ->assertJsonPath('data.closed_at', null);
        $ticket = Ticket::query()->sole();
        $this->assertMatchesRegularExpression('/^TKT-[0-9A-HJKMNP-TV-Z]{26}$/', $ticket->reference);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id, 'requester_id' => $requester->id, 'technician_id' => null,
            'title' => $payload['title'], 'description' => $payload['description'],
            'priority' => 'normal', 'status' => 'open',
        ]);
        $event = TicketEvent::query()->sole();
        $this->assertSame($ticket->id, $event->ticket_id);
        $this->assertSame($requester->id, $event->actor_id);
        $this->assertSame('created', $event->type);
        $this->assertNotNull($event->occurred_at);
        $this->assertEqualsCanonicalizing([
            'id', 'reference', 'title', 'description', 'priority', 'status', 'category', 'requester',
            'technician', 'created_at', 'updated_at', 'resolved_at', 'closed_at', 'resolution_note',
            'asset_id', 'asset', 'asset_can_view',
        ], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($response->json('data.requester')));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($response->json('data.category')));
    }

    public function test_repeated_api_creations_generate_distinct_references(): void
    {
        $requester = User::factory()->create();
        $payload = $this->payload();
        $first = $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/tickets', $payload)->assertCreated();

        $this->assertNotSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('ticket_events', 2);
    }

    public static function acceptedValues(): array
    {
        return [
            'minimum lengths and low priority' => [3, 10, 'low'],
            'maximum lengths and normal priority' => [255, 10000, 'normal'],
            'high priority' => [20, 40, 'high'],
            'urgent priority' => [20, 40, 'urgent'],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_accepts_length_boundaries_and_each_priority(int $titleLength, int $descriptionLength, string $priority): void
    {
        $requester = User::factory()->create();
        $payload = $this->payload([
            'title' => str_repeat('a', $titleLength), 'description' => str_repeat('b', $descriptionLength), 'priority' => $priority,
        ]);

        $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $payload)->assertCreated()
            ->assertJsonPath('data.title', $payload['title'])->assertJsonPath('data.description', $payload['description'])
            ->assertJsonPath('data.priority', $priority);
    }

    public static function invalidPayloads(): array
    {
        return [
            'short title' => [['title' => 'ab'], 'title'],
            'long title' => [['title' => str_repeat('a', 256)], 'title'],
            'null title' => [['title' => null], 'title'],
            'array title' => [['title' => ['a']], 'title'],
            'short description' => [['description' => 'too short'], 'description'],
            'long description' => [['description' => str_repeat('a', 10001)], 'description'],
            'null description' => [['description' => null], 'description'],
            'nonexistent category' => [['category_id' => 999999], 'category_id'],
            'null category' => [['category_id' => null], 'category_id'],
            'array category' => [['category_id' => [1]], 'category_id'],
            'unknown priority' => [['priority' => 'critical'], 'priority'],
            'null priority' => [['priority' => null], 'priority'],
            'array priority' => [['priority' => ['low']], 'priority'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_without_ticket_or_event(array $overrides, string $field): void
    {
        $requester = User::factory()->create();

        $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $this->payload($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function forbiddenFields(): array
    {
        $cases = [];
        foreach (['id', 'reference', 'requester_id', 'technician_id', 'status', 'resolved_at', 'closed_at', 'created_at', 'updated_at', 'requester', 'events', 'unexpected'] as $field) {
            $cases[$field.' value'] = [$field, 'forged'];
            $cases[$field.' null'] = [$field, null];
        }

        return $cases;
    }

    #[DataProvider('forbiddenFields')]
    public function test_rejects_every_server_owned_or_unknown_attribute_even_when_null(string $field, mixed $value): void
    {
        $requester = User::factory()->create();

        $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $this->payload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_required_fields_and_inactive_categories_are_rejected(): void
    {
        $requester = User::factory()->create();
        $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'category_id']);
        $category = Category::factory()->inactive()->create();

        $this->postJson('/api/v1/tickets', $this->payload(['category_id' => $category->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_initial_event_failure_rolls_back_the_ticket(): void
    {
        $requester = User::factory()->create();
        $payload = $this->payload();
        $dispatcher = TicketEvent::getEventDispatcher();
        TicketEvent::setEventDispatcher(clone $dispatcher);
        TicketEvent::creating(function (TicketEvent $event): void {
            throw new RuntimeException('Simulated initial event failure');
        });

        try {
            $this->actingAs($requester, 'web')->postJson('/api/v1/tickets', $payload)->assertStatus(500);
            $this->assertDatabaseCount('tickets', 0);
            $this->assertDatabaseCount('ticket_events', 0);
        } finally {
            TicketEvent::setEventDispatcher($dispatcher);
        }

        $this->postJson('/api/v1/tickets', $payload)->assertCreated();
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_events', 1);
    }
}
