<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketModelTest extends TestCase
{
    use RefreshDatabase;

    public static function visibilityMatrix(): array
    {
        return [
            'employee' => ['employee', true, 1],
            'technician' => ['technician', true, 2],
            'admin' => ['admin', true, 3],
            'inactive employee' => ['employee', false, 0],
            'inactive technician' => ['technician', false, 0],
            'inactive admin' => ['admin', false, 0],
            'unknown role' => ['unknown', true, 0],
        ];
    }

    #[DataProvider('visibilityMatrix')]
    public function test_scope_and_policy_enforce_role_and_active_state(string $role, bool $isActive, int $total): void
    {
        $viewer = User::factory()->create(['role' => $role, 'is_active' => $isActive]);
        $own = Ticket::factory()->create(['requester_id' => $viewer->id]);
        $assigned = Ticket::factory()->create(['technician_id' => $viewer->id]);
        $other = Ticket::factory()->create();
        $gate = Gate::forUser($viewer);

        $this->assertSame($total, Ticket::query()->visibleTo($viewer)->count());
        $this->assertSame($total > 0, $gate->allows('viewAny', Ticket::class));
        $this->assertSame($total > 0, $gate->allows('create', Ticket::class));
        $this->assertSame($total > 0, $gate->allows('view', $own));
        $this->assertSame($total >= 2, $gate->allows('view', $assigned));
        $unrelatedDecision = $gate->inspect('view', $other);
        $this->assertSame($total === 3, $unrelatedDecision->allowed());
        if ($total !== 3) {
            $this->assertSame(404, $unrelatedDecision->status());
        }
    }

    public function test_reference_uniqueness_is_enforced_by_the_database(): void
    {
        $ticket = Ticket::factory()->create();
        $this->expectException(QueryException::class);

        Ticket::factory()->create(['reference' => $ticket->reference]);
    }

    public function test_ticket_foreign_keys_reject_a_missing_requester(): void
    {
        $this->expectException(QueryException::class);

        Ticket::factory()->create(['requester_id' => 999999]);
    }

    public function test_created_event_metadata_and_relationships_round_trip(): void
    {
        $ticket = Ticket::factory()->create();
        $event = TicketEvent::factory()->create([
            'ticket_id' => $ticket->id, 'actor_id' => $ticket->requester_id,
            'metadata' => ['status' => 'open', 'priority' => 'normal', 'category_id' => $ticket->category_id],
        ]);

        $this->assertTrue($event->fresh()->ticket->is($ticket));
        $this->assertTrue($event->actor->is($ticket->requester));
        $this->assertSame($event->metadata, $ticket->events()->sole()->metadata);
    }

    public function test_service_rechecks_a_category_that_was_deactivated_after_validation(): void
    {
        $actor = User::factory()->create();
        $category = Category::factory()->create();
        $attributes = ['category_id' => $category->id, 'title' => 'Printer problem', 'description' => 'The printer has stopped responding.'];
        $category->update(['is_active' => false]);

        try {
            app(TicketService::class)->create($actor, $attributes);
            $this->fail('A category deactivated after validation must not receive a new ticket.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function revokedActors(): array
    {
        return ['disabled' => [['is_active' => false]], 'unknown role' => [['role' => 'unknown']]];
    }

    #[DataProvider('revokedActors')]
    public function test_service_rechecks_actor_permissions_instead_of_trusting_stale_instance(array $changes): void
    {
        $actor = User::factory()->create();
        $category = Category::factory()->create();
        $this->actingAs($actor, 'web');
        User::query()->whereKey($actor->id)->update($changes);

        try {
            app(TicketService::class)->create($actor, [
                'category_id' => $category->id, 'title' => 'Printer problem', 'description' => 'The printer has stopped responding.',
            ]);
            $this->fail('A revoked actor must not create a ticket.');
        } catch (AuthorizationException|HttpResponseException $exception) {
            if (isset($changes['role'])) {
                $this->assertInstanceOf(AuthorizationException::class, $exception);
                $this->assertNotEmpty($exception->getMessage());
                $this->assertSame(403, $exception->status() ?? 403);
                $this->assertAuthenticatedAs($actor, 'web');
            } else {
                $this->assertInstanceOf(HttpResponseException::class, $exception);
                $this->assertSame('account_disabled', $exception->getResponse()->getData(true)['code']);
            }
        }

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function unavailableSessionActors(): array
    {
        return ['deactivated account' => [false], 'deleted account' => [true]];
    }

    #[DataProvider('unavailableSessionActors')]
    public function test_creation_invalidates_session_when_locked_actor_is_inactive_or_missing(bool $deleted): void
    {
        $actor = User::factory()->create(['password' => 'TestPassword123!']);
        $category = Category::factory()->create();
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $actor->email, 'password' => 'TestPassword123!',
        ])->assertOk();
        $this->getJson('/api/user')->assertOk();
        $session = request()->session();
        $sessionId = $session->getId();
        $token = $session->token();
        $session->put('activation_probe', true);

        if ($deleted) {
            User::query()->whereKey($actor->id)->delete();
        } else {
            User::query()->whereKey($actor->id)->update(['is_active' => false]);
        }

        try {
            app(TicketService::class)->create($actor, [
                'category_id' => $category->id, 'title' => 'Printer problem', 'description' => 'The printer has stopped responding.',
            ]);
            $this->fail('Creation must reject an actor revoked after the middleware activation check.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('account_disabled', $exception->getResponse()->getData(true)['code']);
        }

        $this->assertGuest('web');
        $this->assertGuest('sanctum');
        $this->assertNotSame($sessionId, $session->getId());
        $this->assertNotSame($token, $session->token());
        $this->assertFalse($session->has('activation_probe'));
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_events', 0);
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
