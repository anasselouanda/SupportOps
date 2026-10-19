<?php

namespace Tests\Feature\Tickets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TicketAssetTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): static
    {
        $this->app['auth']->guard('sanctum')->forgetUser();

        return $this->actingAs($user, 'web');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['category_id' => Category::factory()->create()->id, 'title' => 'Laptop stopped working',
            'description' => 'The laptop is no longer starting after a normal restart.'], $overrides);
    }

    private function assignedTo(User $user, string $status = 'assigned'): Asset
    {
        $asset = Asset::factory()->create(['status' => $status]);
        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'user_id' => $user->id]);

        return $asset;
    }

    public static function roles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician'], 'admin' => ['admin']];
    }

    #[DataProvider('roles')]
    public function test_active_roles_can_create_ticket_with_their_current_maintenance_asset(string $role): void
    {
        $requester = User::factory()->create(['role' => $role]);
        $asset = $this->assignedTo($requester, 'maintenance');
        $response = $this->asUser($requester)->postJson('/api/v1/tickets', $this->payload(['asset_id' => $asset->id]))
            ->assertCreated()->assertJsonPath('data.asset_id', $asset->id)->assertJsonPath('data.asset.id', $asset->id);
        $this->assertEqualsCanonicalizing(['id', 'inventory_code', 'type', 'brand', 'model', 'serial_number', 'status'], array_keys($response->json('data.asset')));
        $this->assertDatabaseHas('tickets', ['asset_id' => $asset->id, 'requester_id' => $requester->id]);
        $this->assertSame($asset->id, TicketEvent::query()->sole()->metadata['asset_id']);
    }

    public static function nonAdminRoles(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_nonadmin_cannot_select_another_recipient_or_an_asset_from_an_assigned_ticket(string $role): void
    {
        $requester = User::factory()->create(['role' => $role]);
        $foreign = $this->assignedTo(User::factory()->create());
        Ticket::factory()->create(['asset_id' => $foreign->id, 'technician_id' => $requester->id]);
        $before = Ticket::query()->count();
        $this->asUser($requester)->postJson('/api/v1/tickets', $this->payload(['asset_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->assertDatabaseCount('tickets', $before);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_admin_can_select_any_existing_asset_and_optional_null_keeps_legacy_tickets_valid(): void
    {
        $admin = User::factory()->admin()->create();
        $asset = Asset::factory()->create(['status' => 'retired']);
        $this->asUser($admin)->postJson('/api/v1/tickets', $this->payload(['asset_id' => $asset->id]))
            ->assertCreated()->assertJsonPath('data.asset_id', $asset->id);
        $this->postJson('/api/v1/tickets', $this->payload())->assertCreated()->assertJsonPath('data.asset_id', null)->assertJsonPath('data.asset', null);
        $this->postJson('/api/v1/tickets', $this->payload(['asset_id' => null]))->assertCreated()->assertJsonPath('data.asset', null);
        $this->postJson('/api/v1/tickets', $this->payload(['asset_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->assertDatabaseCount('tickets', 3);
    }

    public function test_old_ticket_link_survives_transfer_and_retirement_without_recipient_leak(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create();
        $newRecipient = User::factory()->create();
        $asset = $this->assignedTo($owner);
        $ticketId = $this->asUser($owner)->postJson('/api/v1/tickets', $this->payload(['asset_id' => $asset->id]))->assertCreated()->json('data.id');
        $base = '/api/v1/tickets/'.$ticketId;
        $this->asUser($admin)->patchJson($base.'/assignment', ['technician_id' => $technician->id])->assertOk();
        $this->asUser($technician)->getJson($base)->assertOk()->assertJsonPath('data.asset_can_view', true);
        $this->asUser($admin)->patchJson('/api/v1/assets/'.$asset->id.'/assignment', ['user_id' => $newRecipient->id])->assertOk();

        $response = $this->asUser($owner)->getJson($base)->assertOk()->assertJsonPath('data.asset_id', $asset->id)
            ->assertJsonPath('data.asset_can_view', false);
        $this->assertEqualsCanonicalizing(['id', 'inventory_code', 'type', 'brand', 'model', 'serial_number', 'status'], array_keys($response->json('data.asset')));
        $this->assertStringNotContainsString($newRecipient->email, $response->getContent());
        $this->assertStringNotContainsString($newRecipient->name, $response->getContent());
        $this->getJson('/api/v1/assets/'.$asset->id)->assertNotFound();
        $this->asUser($admin)->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => 'retired'])->assertOk();
        $this->asUser($owner)->getJson($base)->assertOk()->assertJsonPath('data.asset_id', $asset->id)
            ->assertJsonPath('data.asset.status', 'retired')->assertJsonPath('data.asset_can_view', false);
        $this->assertDatabaseHas('tickets', ['id' => $ticketId, 'asset_id' => $asset->id]);
    }

    public function test_omitted_asset_is_preserved_after_transfer_while_explicit_null_is_admin_only(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $asset = $this->assignedTo($owner);
        $ticket = Ticket::factory()->create(['requester_id' => $owner->id, 'asset_id' => $asset->id]);
        $base = '/api/v1/tickets/'.$ticket->id;
        $this->asUser($admin)->patchJson('/api/v1/assets/'.$asset->id.'/assignment', ['user_id' => User::factory()->create()->id])->assertOk();
        $this->asUser($owner)->patchJson($base, ['title' => 'Updated after transfer'])->assertOk()->assertJsonPath('data.asset_id', $asset->id);
        $this->patchJson($base, ['asset_id' => null])->assertForbidden();
        $this->asUser($admin)->patchJson($base, ['description' => 'A later change must preserve this historical link.'])->assertOk()->assertJsonPath('data.asset_id', $asset->id);
        $before = TicketEvent::query()->count();
        $this->patchJson($base, ['asset_id' => $asset->id])->assertOk();
        $this->assertDatabaseCount('ticket_events', $before);
        $this->patchJson($base, ['asset_id' => null])->assertOk()->assertJsonPath('data.asset_id', null)->assertJsonPath('data.asset', null);
        $event = TicketEvent::query()->where('type', 'asset_changed')->sole();
        $this->assertSame(['asset_id' => $asset->id], $event->metadata['before']);
        $this->assertSame(['asset_id' => null], $event->metadata['after']);
        $this->assertSame($admin->id, $event->actor_id);
    }

    public function test_admin_correction_records_only_asset_event_and_closed_ticket_refuses_it(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Asset::factory()->create();
        $second = Asset::factory()->create();
        $ticket = Ticket::factory()->create(['asset_id' => $first->id]);
        $this->asUser($admin)->patchJson('/api/v1/tickets/'.$ticket->id, ['asset_id' => $second->id])->assertOk()->assertJsonPath('data.asset_id', $second->id);
        $event = TicketEvent::query()->sole();
        $this->assertSame('asset_changed', $event->type);
        $this->assertSame(['before' => ['asset_id' => $first->id], 'after' => ['asset_id' => $second->id]], $event->metadata);
        $ticket->refresh()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['asset_id' => null])->assertUnprocessable();
        $this->assertSame($second->id, $ticket->fresh()->asset_id);
        $this->assertDatabaseCount('ticket_events', 1);
    }

    public function test_association_event_failure_rolls_back_asset_and_other_ticket_edits(): void
    {
        $admin = User::factory()->admin()->create();
        $asset = Asset::factory()->create();
        $ticket = Ticket::factory()->create();
        $before = $ticket->fresh()->getAttributes();
        $dispatcher = TicketEvent::getEventDispatcher();
        TicketEvent::setEventDispatcher(clone $dispatcher);
        TicketEvent::creating(function (TicketEvent $event): void {
            if ($event->type === 'asset_changed') {
                throw new RuntimeException('Simulated association event failure.');
            }
        });
        try {
            $this->asUser($admin)->patchJson('/api/v1/tickets/'.$ticket->id, ['asset_id' => $asset->id, 'title' => 'Must roll back too'])->assertStatus(500);
            $this->assertSame($before, $ticket->fresh()->getAttributes());
            $this->assertDatabaseCount('ticket_events', 0);
        } finally {
            TicketEvent::setEventDispatcher($dispatcher);
        }
    }
}
