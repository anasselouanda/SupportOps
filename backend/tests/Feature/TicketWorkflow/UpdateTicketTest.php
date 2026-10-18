<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateTicketTest extends TestCase
{
    use RefreshDatabase;

    public static function ownerRoles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician'], 'admin' => ['admin']];
    }

    #[DataProvider('ownerRoles')]
    public function test_requester_can_edit_business_fields_on_an_open_unassigned_ticket(string $role): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id, 'title' => 'Original title']);
        $category = Category::factory()->create();
        $before = $ticket->only(['title', 'description', 'category_id']);
        $after = ['title' => 'Corrected title', 'description' => 'Updated description with useful detail.', 'category_id' => $category->id];

        $this->actingAs($owner, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, $after)
            ->assertOk()->assertJsonPath('data.title', $after['title'])->assertJsonPath('data.category.id', $category->id);

        $event = TicketEvent::query()->sole();
        $this->assertSame('updated', $event->type);
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(['before' => $before, 'after' => $after], $event->metadata);
    }

    public function test_admin_can_edit_a_resolved_ticket_and_records_priority_as_a_separate_event(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['status' => 'resolved', 'title' => 'Original title', 'resolved_at' => now(), 'resolution_note' => 'Fixed earlier.']);

        $this->actingAs($admin, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, ['title' => 'Corrected title', 'priority' => 'urgent'])
            ->assertOk()->assertJsonPath('data.priority', 'urgent')->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'Fixed earlier.');

        $events = TicketEvent::query()->orderBy('id')->get()->keyBy('type');
        $this->assertCount(2, $events);
        $this->assertSame(['before' => ['title' => 'Original title'], 'after' => ['title' => 'Corrected title']], $events['updated']->metadata);
        $this->assertSame(['before' => ['priority' => 'normal'], 'after' => ['priority' => 'urgent']], $events['priority_changed']->metadata);
    }

    public static function forbiddenOwnerEdits(): array
    {
        return [
            'assigned employee' => ['employee', 'open', true],
            'started employee' => ['employee', 'in_progress', false],
            'resolved employee' => ['employee', 'resolved', false],
            'assigned technician requester' => ['technician', 'open', true],
            'closed admin' => ['admin', 'closed', false],
        ];
    }

    #[DataProvider('forbiddenOwnerEdits')]
    public function test_requester_edit_conditions_are_enforced(string $role, string $status, bool $assigned): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create([
            'requester_id' => $owner->id, 'status' => $status,
            'technician_id' => $assigned ? User::factory()->technician() : null,
        ]);

        $this->actingAs($owner, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, ['title' => 'Forbidden change'])->assertUnprocessable();

        $this->assertSame($ticket->title, $ticket->fresh()->title);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_assigned_technician_cannot_edit_another_requesters_business_fields(): void
    {
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::factory()->create(['technician_id' => $technician->id]);

        $this->actingAs($technician, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, ['description' => 'Unauthorized change to ticket.'])
            ->assertForbidden();
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function nonAdminPriorities(): array
    {
        return ['employee same value' => ['employee', 'normal'], 'employee changed' => ['employee', 'urgent'], 'technician same value' => ['technician', 'normal']];
    }

    #[DataProvider('nonAdminPriorities')]
    public function test_priority_is_forbidden_to_non_admin_even_if_unchanged(string $role, string $priority): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);

        $this->actingAs($owner, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, ['priority' => $priority])->assertForbidden();

        $this->assertSame('normal', $ticket->fresh()->priority);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_same_values_do_not_save_or_create_an_event(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id, 'updated_at' => now()->subDay()]);
        $before = $ticket->getRawOriginal('updated_at');
        $this->travel(5)->minutes();

        $this->actingAs($owner, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, $ticket->only(['title', 'description', 'category_id']))
            ->assertOk();

        $this->assertSame($before, $ticket->fresh()->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function invalidUpdates(): array
    {
        return [
            'empty' => [[], null],
            'short title' => [['title' => 'ab'], 'title'],
            'long title' => [['title' => str_repeat('a', 256)], 'title'],
            'null title' => [['title' => null], 'title'],
            'short description' => [['description' => 'short'], 'description'],
            'long description' => [['description' => str_repeat('a', 10001)], 'description'],
            'null description' => [['description' => null], 'description'],
            'unknown category' => [['category_id' => 999999], 'category_id'],
            'null category' => [['category_id' => null], 'category_id'],
            'unknown priority' => [['priority' => 'critical'], 'priority'],
            'null priority' => [['priority' => null], 'priority'],
            'status bypass' => [['status' => 'closed'], 'status'],
            'assignment bypass' => [['technician_id' => null], 'technician_id'],
            'requester bypass' => [['requester_id' => 1], 'requester_id'],
            'resolution bypass' => [['resolution_note' => null], 'resolution_note'],
            'resolved date bypass' => [['resolved_at' => null], 'resolved_at'],
            'closed date bypass' => [['closed_at' => null], 'closed_at'],
            'unknown field' => [['unexpected' => null], 'unexpected'],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_or_forged_partial_updates_do_not_write(array $payload, ?string $field): void
    {
        $ticket = Ticket::factory()->create();
        $before = $ticket->fresh()->getAttributes();
        $response = $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id, $payload)->assertUnprocessable();
        if ($field !== null) {
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame($before, $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_inactive_category_is_rejected_even_for_admin(): void
    {
        $ticket = Ticket::factory()->create();
        $category = Category::factory()->inactive()->create();

        $this->actingAs(User::factory()->admin()->create(), 'web')->patchJson('/api/v1/tickets/'.$ticket->id, ['category_id' => $category->id])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->assertSame($ticket->category_id, $ticket->fresh()->category_id);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function boundaries(): array
    {
        return ['minimum' => [3, 10], 'maximum' => [255, 10000]];
    }

    #[DataProvider('boundaries')]
    public function test_update_accepts_text_length_boundaries(int $titleLength, int $descriptionLength): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);

        $this->actingAs($owner, 'web')->patchJson('/api/v1/tickets/'.$ticket->id, [
            'title' => str_repeat('a', $titleLength), 'description' => str_repeat('b', $descriptionLength),
        ])->assertOk();

        $this->assertSame($titleLength, strlen($ticket->fresh()->title));
        $this->assertSame($descriptionLength, strlen($ticket->fresh()->description));
    }
}
