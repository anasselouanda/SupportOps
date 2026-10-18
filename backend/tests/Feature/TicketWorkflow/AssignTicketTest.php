<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_assigns_replaces_and_removes_technician_without_changing_status(): void
    {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->technician()->create();
        $second = User::factory()->technician()->create();
        $ticket = Ticket::factory()->create();
        $this->actingAs($admin, 'web');

        foreach ([[$first->id, null], [$second->id, $first->id], [null, $second->id]] as [$next, $previous]) {
            $this->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $next])
                ->assertOk()->assertJsonPath('data.status', 'open');
            $this->assertSame($next, $ticket->fresh()->technician_id);
            $event = TicketEvent::query()->latest('id')->firstOrFail();
            $this->assertSame('assignment_changed', $event->type);
            $this->assertSame($admin->id, $event->actor_id);
            $this->assertSame(['before' => ['technician_id' => $previous], 'after' => ['technician_id' => $next]], $event->metadata);
        }
        $this->assertDatabaseCount('ticket_events', 3);
    }

    public function test_admin_can_replace_assignment_while_in_progress(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'technician_id' => User::factory()->technician()]);
        $target = User::factory()->technician()->create();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $target->id])
            ->assertOk()->assertJsonPath('data.technician.id', $target->id)->assertJsonPath('data.status', 'in_progress');
    }

    public static function forbiddenRoles(): array
    {
        return ['employee requester' => ['employee', true], 'technician requester' => ['technician', true], 'assigned technician' => ['technician', false]];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_admin_visible_users_cannot_assign(string $role, bool $owns): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create([
            'requester_id' => $owns ? $actor->id : User::factory(),
            'technician_id' => $owns ? null : $actor->id,
        ]);

        $this->actingAs($actor, 'web')->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => null])
            ->assertForbidden();
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function invalidTargets(): array
    {
        return ['employee' => ['employee', true], 'administrator' => ['admin', true], 'inactive technician' => ['technician', false]];
    }

    #[DataProvider('invalidTargets')]
    public function test_assignment_target_must_be_an_active_technician(string $role, bool $isActive): void
    {
        $target = User::factory()->create(['role' => $role, 'is_active' => $isActive]);
        $ticket = Ticket::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $target->id])
            ->assertUnprocessable()->assertJsonValidationErrors('technician_id');

        $this->assertNull($ticket->fresh()->technician_id);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function invalidStates(): array
    {
        return ['resolved' => ['resolved'], 'closed' => ['closed']];
    }

    #[DataProvider('invalidStates')]
    public function test_assignment_is_rejected_on_resolved_and_closed_tickets(string $status): void
    {
        $ticket = Ticket::factory()->create(['status' => $status]);
        $target = User::factory()->technician()->create();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $target->id])->assertUnprocessable();

        $this->assertNull($ticket->fresh()->technician_id);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_assignment_cannot_be_removed_while_in_progress(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'technician_id' => User::factory()->technician()]);

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => null])->assertUnprocessable();

        $this->assertSame($ticket->technician_id, $ticket->fresh()->technician_id);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_identical_assignment_does_not_touch_updated_at_or_history(): void
    {
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::factory()->create(['technician_id' => $technician->id, 'updated_at' => now()->subDay()]);
        $before = $ticket->getRawOriginal('updated_at');
        $this->travel(5)->minutes();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $technician->id])->assertOk();

        $this->assertSame($before, $ticket->fresh()->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_identical_assignment_still_rejects_a_technician_deactivated_since_assignment(): void
    {
        $technician = User::factory()->technician()->inactive()->create();
        $ticket = Ticket::factory()->create(['technician_id' => $technician->id]);

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', ['technician_id' => $technician->id])->assertUnprocessable();

        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function malformedAssignments(): array
    {
        return [
            'missing key' => [[], 'technician_id'],
            'nonexistent user' => [['technician_id' => 999999], 'technician_id'],
            'array value' => [['technician_id' => [1]], 'technician_id'],
            'nonnumeric value' => [['technician_id' => 'abc'], 'technician_id'],
            'forged state' => [['technician_id' => null, 'status' => 'in_progress'], 'status'],
            'forged actor' => [['technician_id' => null, 'actor_id' => null], 'actor_id'],
        ];
    }

    #[DataProvider('malformedAssignments')]
    public function test_assignment_validation_rejects_missing_invalid_and_forged_fields(array $payload, string $field): void
    {
        $ticket = Ticket::factory()->create();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/assignment', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('ticket_events', 0);
    }
}
