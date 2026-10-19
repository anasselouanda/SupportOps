<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetAccessTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): static
    {
        $this->app['auth']->guard('sanctum')->forgetUser();

        return $this->actingAs($user, 'web');
    }

    private function assignedTo(User $user, array $attributes = []): Asset
    {
        $asset = Asset::factory()->create(array_replace(['status' => 'assigned'], $attributes));
        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'user_id' => $user->id]);

        return $asset;
    }

    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '', []], 'create' => ['POST', '', []], 'show' => ['GET', '/1', []],
            'edit' => ['PATCH', '/1', ['brand' => 'Changed']],
            'assign' => ['PATCH', '/1/assignment', ['user_id' => null]],
            'state' => ['PATCH', '/1/status', ['status' => 'retired']],
            'history' => ['GET', '/1/assignments', []],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_requires_authentication_and_an_active_account(string $method, string $suffix, array $payload): void
    {
        $this->json($method, '/api/v1/assets'.$suffix, $payload)->assertUnauthorized();
        $this->asUser(User::factory()->admin()->inactive()->create())
            ->json($method, '/api/v1/assets'.$suffix, $payload)->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertGuest('web');
    }

    public function test_employee_scope_includes_current_maintenance_but_never_past_assignments(): void
    {
        $employee = User::factory()->create();
        $assigned = $this->assignedTo($employee);
        $maintenance = $this->assignedTo($employee, ['status' => 'maintenance']);
        $past = Asset::factory()->create();
        AssetAssignment::factory()->create(['asset_id' => $past->id, 'user_id' => $employee->id, 'ended_at' => now()]);
        $other = $this->assignedTo(User::factory()->create());

        $response = $this->asUser($employee)->getJson('/api/v1/assets')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$assigned->id, $maintenance->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/assets/'.$assigned->id)->assertOk()->assertJsonPath('data.current_assignment.user.id', $employee->id);
        foreach ([$past->id, $other->id, 999999] as $id) {
            $this->getJson('/api/v1/assets/'.$id)->assertNotFound();
            $this->patchJson('/api/v1/assets/'.$id, ['brand' => 'Changed'])->assertNotFound();
            $this->getJson('/api/v1/assets/'.$id.'/assignments')->assertNotFound();
        }
    }

    public function test_technician_scope_uses_assigned_tickets_and_exposes_only_minimal_fields(): void
    {
        $technician = User::factory()->technician()->create();
        $visible = $this->assignedTo(User::factory()->create());
        $createdOnly = Asset::factory()->create();
        Ticket::factory()->create(['asset_id' => $visible->id, 'technician_id' => $technician->id]);
        Ticket::factory()->create(['asset_id' => $visible->id, 'technician_id' => $technician->id]);
        Ticket::factory()->create(['asset_id' => $createdOnly->id, 'requester_id' => $technician->id]);
        $unrelated = Asset::factory()->create();

        $response = $this->asUser($technician)->getJson('/api/v1/assets')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->assertEqualsCanonicalizing(['id', 'inventory_code', 'type', 'brand', 'model', 'serial_number', 'status'], array_keys($response->json('data.0')));
        $detail = $this->getJson('/api/v1/assets/'.$visible->id)->assertOk();
        $this->assertSame($response->json('data.0'), $detail->json('data'));
        $this->getJson('/api/v1/assets/'.$createdOnly->id)->assertNotFound();
        $this->getJson('/api/v1/assets/'.$unrelated->id)->assertNotFound();
        Ticket::query()->where('asset_id', $visible->id)->update(['technician_id' => null]);
        $this->getJson('/api/v1/assets/'.$visible->id)->assertNotFound();
    }

    public static function nonAdministrators(): array
    {
        return ['employee' => ['employee'], 'technician' => ['technician']];
    }

    #[DataProvider('nonAdministrators')]
    public function test_visible_resources_refuse_all_administrative_actions_without_logging_out(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role]);
        $asset = $this->assignedTo($viewer);
        if ($role === 'technician') {
            Ticket::factory()->create(['asset_id' => $asset->id, 'technician_id' => $viewer->id]);
        }
        $this->asUser($viewer)->postJson('/api/v1/assets', [])->assertForbidden();
        $base = '/api/v1/assets/'.$asset->id;
        $this->patchJson($base, ['brand' => 'Changed'])->assertForbidden();
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertForbidden();
        $this->patchJson($base.'/status', ['status' => 'retired'])->assertForbidden();
        $this->getJson($base.'/assignments')->assertForbidden();
        $this->assertAuthenticatedAs($viewer, 'web');
        $this->assertSame('assigned', $asset->fresh()->status);
    }

    public function test_admin_keeps_disabled_recipient_and_history_has_minimal_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->inactive()->create();
        $asset = $this->assignedTo($recipient);

        $this->asUser($admin)->getJson('/api/v1/assets/'.$asset->id)->assertOk()
            ->assertJsonPath('data.current_assignment.user.id', $recipient->id);
        $history = $this->getJson('/api/v1/assets/'.$asset->id.'/assignments')->assertOk()->assertJsonCount(1, 'data');
        $this->assertEqualsCanonicalizing(['id', 'asset_id', 'user', 'assigned_by', 'started_at', 'ended_at'], array_keys($history->json('data.0')));
        foreach (['user', 'assigned_by'] as $profile) {
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($history->json('data.0.'.$profile)));
        }
    }

    public function test_unknown_role_is_forbidden_even_for_an_existing_assignment(): void
    {
        $user = User::factory()->create(['role' => 'unsupported']);
        $asset = $this->assignedTo($user);
        $this->asUser($user)->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/assets/'.$asset->id)->assertForbidden();
    }

    public function test_admin_list_eager_loads_assignment_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        for ($index = 0; $index < 18; $index++) {
            $this->assignedTo(User::factory()->create());
        }
        $this->asUser($admin);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->getJson('/api/v1/assets?per_page=1')->assertOk();
            $singleCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->getJson('/api/v1/assets?per_page=25')->assertOk()->assertJsonCount(18, 'data');
            $this->assertLessThanOrEqual($singleCount + 2, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
