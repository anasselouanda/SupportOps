<?php

namespace Tests\Feature\TicketWorkflow;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketWorkflowAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [
            'edit' => ['PATCH', '', ['title' => 'Changed title']],
            'assignment' => ['PATCH', '/assignment', ['technician_id' => null]],
            'status' => ['PATCH', '/status', ['status' => 'in_progress']],
            'comment create' => ['POST', '/comments', ['content' => 'Useful comment.']],
            'comment list' => ['GET', '/comments', []],
            'event list' => ['GET', '/events', []],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_anonymous_workflow_requests_are_unauthorized(string $method, string $suffix, array $payload): void
    {
        $this->json($method, '/api/v1/tickets/999999'.$suffix, $payload)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_real_session_is_invalidated_on_each_workflow_endpoint(string $method, string $suffix, array $payload): void
    {
        $user = User::factory()->admin()->create(['password' => 'SecurePassword123!']);
        $ticket = Ticket::factory()->create();
        $this->withHeader('Origin', 'http://127.0.0.1:5173')->postJson('/api/login', [
            'email' => $user->email, 'password' => 'SecurePassword123!',
        ])->assertOk();
        $sessionId = $this->app['session']->getId();
        $token = $this->app['session']->token();
        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->json($method, '/api/v1/tickets/'.$ticket->id.$suffix, $payload)
            ->assertForbidden()->assertJsonPath('code', 'account_disabled');

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, $this->app['session']->getId());
        $this->assertNotSame($token, $this->app['session']->token());
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    #[DataProvider('endpoints')]
    public function test_outside_scope_is_404_before_invalid_body_or_query_validation(string $method, string $suffix, array $payload): void
    {
        $viewer = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $url = '/api/v1/tickets/'.$ticket->id.$suffix;
        if ($method === 'GET') {
            $url .= '?per_page=101&unknown=1';
        }

        $this->actingAs($viewer, 'web')->json($method, $url, $method === 'GET' ? [] : ['unexpected' => ['invalid']])
            ->assertNotFound();

        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    #[DataProvider('endpoints')]
    public function test_missing_ticket_is_404_before_invalid_body_or_query_validation(string $method, string $suffix, array $payload): void
    {
        $url = '/api/v1/tickets/999999'.$suffix;
        if ($method === 'GET') {
            $url .= '?per_page=101';
        }

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->json($method, $url, $method === 'GET' ? [] : ['unexpected' => true])->assertNotFound();
    }

    #[DataProvider('endpoints')]
    public function test_unknown_active_role_cannot_enter_the_ticket_scope(string $method, string $suffix, array $payload): void
    {
        $viewer = User::factory()->create(['role' => 'unknown']);
        $ticket = Ticket::factory()->create(['requester_id' => $viewer->id, 'technician_id' => $viewer->id]);

        $this->actingAs($viewer, 'web')->json($method, '/api/v1/tickets/'.$ticket->id.$suffix, $payload)->assertNotFound();

        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public static function closedMutations(): array
    {
        return [
            'edit' => ['PATCH', '', ['title' => 'Forbidden edit']],
            'assign' => ['PATCH', '/assignment', ['technician_id' => null]],
            'reopen' => ['PATCH', '/status', ['status' => 'open']],
            'comment' => ['POST', '/comments', ['content' => 'Forbidden comment.']],
        ];
    }

    #[DataProvider('closedMutations')]
    public function test_closed_ticket_is_immutable_even_for_admin(string $method, string $suffix, array $payload): void
    {
        $ticket = Ticket::factory()->create([
            'status' => 'closed', 'resolution_note' => 'Resolved and accepted.', 'resolved_at' => now()->subDay(), 'closed_at' => now(),
        ]);
        $before = $ticket->fresh()->getAttributes();

        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->json($method, '/api/v1/tickets/'.$ticket->id.$suffix, $payload)->assertUnprocessable();

        $this->assertSame($before, $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('ticket_comments', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }
}
