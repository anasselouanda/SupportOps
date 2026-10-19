<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): static
    {
        $this->app['auth']->guard('sanctum')->forgetUser();

        return $this->actingAs($user, 'web');
    }

    public function test_recipient_selection_is_admin_only_active_searchable_paginated_and_minimal(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Administrator']);
        $first = User::factory()->create(['name' => 'Recipient First', 'email' => 'first@selection.test']);
        $second = User::factory()->technician()->create(['name' => 'Recipient Second', 'email' => 'second@selection.test']);
        User::factory()->inactive()->create(['name' => 'Recipient Inactive', 'email' => 'inactive@selection.test']);
        User::factory()->create(['name' => 'Other Person', 'email' => 'unrelated@example.test']);

        $firstPage = $this->asUser($admin)->getJson('/api/v1/assets/recipients?search=selection.test&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $secondPage = $this->getJson('/api/v1/assets/recipients?search=selection.test&per_page=1&page=2')->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], [$firstPage->json('data.0.id'), $secondPage->json('data.0.id')]);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($firstPage->json('data.0')));
        $this->getJson('/api/v1/assets/recipients?is_active=0')->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->getJson('/api/v1/assets/recipients?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->asUser($first)->getJson('/api/v1/assets/recipients')->assertForbidden();
        $this->asUser($second)->getJson('/api/v1/assets/recipients')->assertForbidden();
    }

    public function test_ticket_asset_selection_uses_recipient_scope_even_for_technicians(): void
    {
        $technician = User::factory()->technician()->create();
        $own = Asset::factory()->create(['status' => 'maintenance']);
        AssetAssignment::factory()->create(['asset_id' => $own->id, 'user_id' => $technician->id]);
        $ticketOnly = Asset::factory()->create();
        Ticket::factory()->create(['asset_id' => $ticketOnly->id, 'technician_id' => $technician->id]);
        $past = Asset::factory()->create();
        AssetAssignment::factory()->create(['asset_id' => $past->id, 'user_id' => $technician->id, 'ended_at' => now()]);

        $response = $this->asUser($technician)->getJson('/api/v1/ticket-assets')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id);
        $this->assertEqualsCanonicalizing(['id', 'inventory_code', 'type', 'brand', 'model', 'serial_number', 'status'], array_keys($response->json('data.0')));
        $this->getJson('/api/v1/ticket-assets?status=maintenance')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asUser(User::factory()->admin()->create())->getJson('/api/v1/ticket-assets')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/ticket-assets?user_id='.$technician->id)->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_selection_endpoints_require_authentication_and_active_account(): void
    {
        foreach (['/api/v1/assets/recipients', '/api/v1/ticket-assets'] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
        $inactive = User::factory()->admin()->inactive()->create();
        foreach (['/api/v1/assets/recipients', '/api/v1/ticket-assets'] as $uri) {
            $this->asUser($inactive)->getJson($uri)->assertForbidden()->assertJsonPath('code', 'account_disabled');
        }
    }
}
