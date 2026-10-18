<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\TicketCommentService;
use App\Services\TicketWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TicketMutationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): static
    {
        $this->app['auth']->guard('sanctum')->forgetUser();

        return $this->actingAs($user, 'web');
    }

    public static function mutationFailures(): array
    {
        return [
            'business edit' => ['update', 'open', 'updated'],
            'priority change' => ['priority', 'open', 'priority_changed'],
            'combined edit fails after first event' => ['combined', 'open', 'priority_changed'],
            'assignment change' => ['assignment', 'open', 'assignment_changed'],
            'start' => ['start', 'open', 'status_changed'],
            'resolution' => ['resolve', 'in_progress', 'resolved'],
            'closure' => ['close', 'resolved', 'closed'],
            'reopening' => ['reopen', 'resolved', 'reopened'],
            'comment' => ['comment', 'open', 'comment_added'],
        ];
    }

    #[DataProvider('mutationFailures')]
    public function test_event_failure_rolls_back_every_business_write_and_previous_event(string $action, string $status, string $eventType): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create([
            'technician_id' => User::factory()->technician(), 'status' => $status,
            'resolution_note' => $status === 'resolved' ? 'Existing resolution.' : null,
            'resolved_at' => $status === 'resolved' ? now()->subDay() : null,
        ]);
        $before = $ticket->fresh()->getAttributes();
        $replacement = User::factory()->technician()->create();
        [$method, $suffix, $payload] = match ($action) {
            'update' => ['PATCH', '', ['title' => 'Changed title']],
            'priority' => ['PATCH', '', ['priority' => 'urgent']],
            'combined' => ['PATCH', '', ['title' => 'Changed title', 'priority' => 'urgent']],
            'assignment' => ['PATCH', '/assignment', ['technician_id' => $replacement->id]],
            'start' => ['PATCH', '/status', ['status' => 'in_progress']],
            'resolve' => ['PATCH', '/status', ['status' => 'resolved', 'resolution_note' => 'Fixed completely.']],
            'close' => ['PATCH', '/status', ['status' => 'closed']],
            'reopen' => ['PATCH', '/status', ['status' => 'open']],
            'comment' => ['POST', '/comments', ['content' => 'Helpful update.']],
        };
        $dispatcher = TicketEvent::getEventDispatcher();
        TicketEvent::setEventDispatcher(clone $dispatcher);
        TicketEvent::creating(function (TicketEvent $event) use ($eventType): void {
            if ($event->type === $eventType) {
                throw new RuntimeException('Simulated audit persistence failure.');
            }
        });

        try {
            $this->actingAs($admin, 'web')->json($method, '/api/v1/tickets/'.$ticket->id.$suffix, $payload)->assertStatus(500);
            $this->assertSame($before, $ticket->fresh()->getAttributes());
            $this->assertDatabaseCount('ticket_comments', 0);
            $this->assertDatabaseCount('ticket_events', 0);
        } finally {
            TicketEvent::setEventDispatcher($dispatcher);
        }

        $this->json($method, '/api/v1/tickets/'.$ticket->id.$suffix, $payload)->assertStatus($action === 'comment' ? 201 : 200);
    }

    public function test_comment_service_reloads_ticket_after_a_simulated_intervening_closure(): void
    {
        $owner = User::factory()->create();
        $staleTicket = Ticket::factory()->create(['requester_id' => $owner->id]);
        Ticket::query()->whereKey($staleTicket->id)->update(['status' => 'closed', 'closed_at' => now()]);
        $this->assertSame('open', $staleTicket->status);

        try {
            app(TicketCommentService::class)->create($owner, $staleTicket->id, 'This must not be persisted.');
            $this->fail('The service must reread the closed state.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
        $this->assertSame('closed', $staleTicket->fresh()->status);
    }

    public function test_workflow_service_reloads_admin_role_before_assignment(): void
    {
        $staleAdmin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $staleAdmin->id]);
        $technician = User::factory()->technician()->create();
        User::query()->whereKey($staleAdmin->id)->update(['role' => 'employee']);

        try {
            app(TicketWorkflowService::class)->assign($staleAdmin, $ticket->id, $technician->id);
            $this->fail('A demoted administrator must not assign tickets.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }

        $this->assertNull($ticket->fresh()->technician_id);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_workflow_service_reloads_current_assignment_before_technician_transition(): void
    {
        $technician = User::factory()->technician()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $technician->id, 'technician_id' => $technician->id]);
        Ticket::query()->whereKey($ticket->id)->update(['technician_id' => User::factory()->technician()->create()->id]);

        try {
            app(TicketWorkflowService::class)->changeStatus($technician, $ticket->id, 'in_progress');
            $this->fail('A replaced technician must not start the ticket.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_comment_service_reloads_disabled_actor_and_invalidates_session(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        User::query()->whereKey($owner->id)->update(['is_active' => false]);

        try {
            app(TicketCommentService::class)->create($owner, $ticket->id, 'Must be rejected.');
            $this->fail('A stale active actor must not create comments.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('account_disabled', $exception->getResponse()->getData(true)['code']);
        }

        $this->assertGuest('web');
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_complete_api_workflow_reopens_preserves_assignment_and_keeps_both_resolutions_in_history(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create();
        $category = Category::factory()->create();
        $ticketId = $this->actingAs($owner, 'web')->postJson('/api/v1/tickets', [
            'title' => 'Printer stopped responding', 'description' => 'The office printer is no longer accepting jobs.', 'category_id' => $category->id,
        ])->assertCreated()->json('data.id');
        $base = '/api/v1/tickets/'.$ticketId;

        $this->asUser($admin)->patchJson($base.'/assignment', ['technician_id' => $technician->id])->assertOk();
        $this->asUser($technician)->patchJson($base.'/status', ['status' => 'in_progress'])->assertOk();
        $this->asUser($owner)->postJson($base.'/comments', ['content' => 'The issue affects every workstation.'])->assertCreated();
        $this->asUser($technician)->patchJson($base.'/status', ['status' => 'resolved', 'resolution_note' => 'Restarted the print service.'])->assertOk();
        $this->asUser($owner)->patchJson($base.'/status', ['status' => 'open'])->assertOk()
            ->assertJsonPath('data.technician.id', $technician->id)->assertJsonPath('data.resolved_at', null)->assertJsonPath('data.resolution_note', null);
        $this->asUser($technician)->patchJson($base.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson($base.'/status', ['status' => 'resolved', 'resolution_note' => 'Replaced the failing network cable.'])->assertOk();
        $this->asUser($owner)->patchJson($base.'/status', ['status' => 'closed'])->assertOk()
            ->assertJsonPath('data.resolution_note', 'Replaced the failing network cable.');
        $history = $this->getJson($base.'/events')->assertOk()->assertJsonCount(9, 'data');
        $this->assertSame(['created', 'assignment_changed', 'status_changed', 'comment_added', 'resolved', 'reopened', 'status_changed', 'resolved', 'closed'],
            array_column($history->json('data'), 'type'));
        $this->assertSame('Restarted the print service.', $history->json('data.5.metadata.before.resolution_note'));
        $this->assertSame('Replaced the failing network cable.', $history->json('data.7.metadata.after.resolution_note'));
        $this->getJson($base.'/comments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'closed');
    }
}
