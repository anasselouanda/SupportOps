<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChangeTicketStatusTest extends TestCase
{
    use RefreshDatabase;

    public static function allowedTransitions(): array
    {
        return [
            'admin starts' => ['admin', false, 'open', 'in_progress', 'status_changed'],
            'assigned technician starts' => ['technician', false, 'open', 'in_progress', 'status_changed'],
            'admin resolves' => ['admin', false, 'in_progress', 'resolved', 'resolved'],
            'assigned technician resolves' => ['technician', false, 'in_progress', 'resolved', 'resolved'],
            'admin closes' => ['admin', false, 'resolved', 'closed', 'closed'],
            'employee requester closes' => ['employee', true, 'resolved', 'closed', 'closed'],
            'technician requester closes' => ['technician', true, 'resolved', 'closed', 'closed'],
            'admin reopens' => ['admin', false, 'resolved', 'open', 'reopened'],
            'employee requester reopens' => ['employee', true, 'resolved', 'open', 'reopened'],
            'technician requester reopens' => ['technician', true, 'resolved', 'open', 'reopened'],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_authorized_transition_records_actor_server_time_and_before_after_history(string $role, bool $owns, string $from, string $to, string $eventType): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $technician = $role === 'technician' ? $actor : User::factory()->technician()->create();
        $ticket = Ticket::factory()->create([
            'requester_id' => $owns ? $actor->id : User::factory(), 'technician_id' => $technician->id,
            'status' => $from, 'resolved_at' => $from === 'resolved' ? now()->subDay() : null,
            'resolution_note' => $from === 'resolved' ? 'Previous resolution remains in history.' : null,
        ]);
        $payload = ['status' => $to];
        if ($to === 'resolved') {
            $payload['resolution_note'] = 'The printer is working again.';
        }
        $this->travelTo(Carbon::parse('2026-10-18T14:15:16+01:00'));

        $response = $this->actingAs($actor, 'web')->patchJson('/api/v1/tickets/'.$ticket->id.'/status', $payload)
            ->assertOk()->assertJsonPath('data.status', $to)->assertJsonPath('data.technician.id', $technician->id);

        $event = TicketEvent::query()->sole();
        $this->assertSame($eventType, $event->type);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame('2026-10-18T13:15:16+00:00', $event->occurred_at->toIso8601String());
        $this->assertSame($from, $event->metadata['before']['status']);
        $this->assertSame($to, $event->metadata['after']['status']);
        if ($to === 'resolved') {
            $response->assertJsonPath('data.resolution_note', $payload['resolution_note']);
            $this->assertSame(0, (new DateTimeImmutable($response->json('data.resolved_at')))->getOffset());
            $this->assertSame('2026-10-18T13:15:16+00:00', $ticket->fresh()->resolved_at->toIso8601String());
            $this->assertSame($payload['resolution_note'], $event->metadata['after']['resolution_note']);
        }
        if ($to === 'closed') {
            $this->assertSame(0, (new DateTimeImmutable($response->json('data.closed_at')))->getOffset());
            $this->assertNotNull($ticket->fresh()->resolved_at);
        }
        if ($to === 'open') {
            $response->assertJsonPath('data.resolved_at', null)->assertJsonPath('data.closed_at', null)
                ->assertJsonPath('data.resolution_note', null);
            $this->assertSame('Previous resolution remains in history.', $event->metadata['before']['resolution_note']);
            $this->assertNotNull($event->metadata['before']['resolved_at']);
        }
    }

    public static function forbiddenTransitions(): array
    {
        return [
            'employee cannot start' => ['employee', true, 'open', 'in_progress'],
            'employee cannot resolve' => ['employee', true, 'in_progress', 'resolved'],
            'unassigned technician requester cannot start' => ['technician', true, 'open', 'in_progress'],
            'unassigned technician requester cannot resolve' => ['technician', true, 'in_progress', 'resolved'],
            'nonrequester technician cannot close' => ['technician', false, 'resolved', 'closed'],
            'nonrequester technician cannot reopen' => ['technician', false, 'resolved', 'open'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_roles_are_rejected_before_invalid_note_validation(string $role, bool $owns, string $from, string $to): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $ticket = Ticket::factory()->create([
            'requester_id' => $owns ? $actor->id : User::factory(),
            'technician_id' => $owns ? User::factory()->technician() : $actor->id, 'status' => $from,
        ]);

        $this->actingAs($actor, 'web')->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => $to, 'resolution_note' => null])
            ->assertForbidden();

        $this->assertSame($from, $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function impossibleTransitions(): array
    {
        $cases = [];
        $allowed = ['open:in_progress', 'in_progress:resolved', 'resolved:closed', 'resolved:open'];
        foreach (['open', 'in_progress', 'resolved', 'closed'] as $from) {
            foreach (['open', 'in_progress', 'resolved', 'closed'] as $to) {
                if (! in_array($from.':'.$to, $allowed, true)) {
                    $cases[$from.' to '.$to] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('impossibleTransitions')]
    public function test_invalid_and_identical_transitions_return_422_for_authorized_admin(string $from, string $to): void
    {
        $ticket = Ticket::factory()->create(['status' => $from, 'technician_id' => User::factory()->technician()]);
        $payload = ['status' => $to];
        if ($to === 'resolved') {
            $payload['resolution_note'] = 'A valid resolution note.';
        }

        $this->actingAs(User::factory()->admin()->create(), 'web')->patchJson('/api/v1/tickets/'.$ticket->id.'/status', $payload)
            ->assertUnprocessable();

        $this->assertSame($from, $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function invalidAssignedTechnicians(): array
    {
        return [
            'unassigned' => [null, true],
            'assigned employee' => ['employee', true],
            'assigned admin' => ['admin', true],
            'inactive assigned technician' => ['technician', false],
        ];
    }

    #[DataProvider('invalidAssignedTechnicians')]
    public function test_start_requires_an_active_assigned_technician_even_for_admin(?string $role, bool $active): void
    {
        $ticket = Ticket::factory()->create([
            'technician_id' => $role === null ? null : User::factory()->create(['role' => $role, 'is_active' => $active])->id,
        ]);

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'])->assertUnprocessable();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function invalidNotes(): array
    {
        return ['null' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 10001)], 'array' => [['a']]];
    }

    #[DataProvider('invalidNotes')]
    public function test_resolution_requires_a_nonblank_note_with_a_maximum_length(mixed $note): void
    {
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'technician_id' => User::factory()->technician()]);

        $this->actingAs(User::factory()->admin()->create(), 'web')->patchJson('/api/v1/tickets/'.$ticket->id.'/status', [
            'status' => 'resolved', 'resolution_note' => $note,
        ])->assertUnprocessable()->assertJsonValidationErrors('resolution_note');

        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_resolution_note_is_required_when_omitted_and_accepts_maximum_length(): void
    {
        $ticket = Ticket::factory()->create(['status' => 'in_progress', 'technician_id' => User::factory()->technician()]);
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'resolved'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution_note');
        $this->patchJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'resolved', 'resolution_note' => str_repeat('a', 10000)])
            ->assertOk()->assertJsonPath('data.resolution_note', str_repeat('a', 10000));
    }

    public static function invalidStatusPayloads(): array
    {
        return [
            'missing state' => [[], 'status'],
            'unknown state' => [['status' => 'archived'], 'status'],
            'array state' => [['status' => ['closed']], 'status'],
            'note not allowed even null' => [['status' => 'in_progress', 'resolution_note' => null], 'resolution_note'],
            'forged time' => [['status' => 'in_progress', 'resolved_at' => null], 'resolved_at'],
            'forged actor' => [['status' => 'in_progress', 'actor_id' => 1], 'actor_id'],
        ];
    }

    #[DataProvider('invalidStatusPayloads')]
    public function test_status_validation_rejects_missing_unknown_and_forged_fields(array $payload, string $field): void
    {
        $ticket = Ticket::factory()->create(['technician_id' => User::factory()->technician()]);

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/tickets/'.$ticket->id.'/status', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('ticket_events', 0);
    }
}
