<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_transfer_and_return_keep_complete_periods_with_server_actors_and_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->create();
        $second = User::factory()->technician()->create();
        $asset = Asset::factory()->create();
        $base = '/api/v1/assets/'.$asset->id;
        $this->travelTo(now()->startOfSecond());
        $this->actingAs($admin, 'web')->patchJson($base.'/assignment', ['user_id' => $first->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.current_assignment.user.id', $first->id);
        $period = AssetAssignment::query()->sole();
        $this->assertSame($admin->id, $period->assigned_by);
        $this->assertTrue($period->started_at->equalTo(now()));
        $this->assertNull($period->ended_at);
        $this->travel(1)->minutes();
        $this->patchJson($base.'/assignment', ['user_id' => $second->id])->assertOk()
            ->assertJsonPath('data.current_assignment.user.id', $second->id);
        $this->assertTrue($period->fresh()->ended_at->equalTo(now()));
        $current = AssetAssignment::query()->whereNull('ended_at')->sole();
        $this->assertSame($second->id, $current->user_id);
        $this->assertSame($admin->id, $current->assigned_by);
        $this->assertTrue($current->started_at->equalTo($period->fresh()->ended_at));
        $this->travel(1)->minutes();
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertOk()
            ->assertJsonPath('data.status', 'available')->assertJsonPath('data.current_assignment', null);
        $this->assertDatabaseCount('asset_assignments', 2);
        $this->assertSame(0, AssetAssignment::query()->whereNull('ended_at')->count());
        $this->assertTrue($current->fresh()->ended_at->equalTo(now()));
        $this->getJson($base.'/assignments?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($base.'/assignments?search=forged')->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    public function test_identical_assignment_and_empty_return_are_true_no_ops_including_timestamps(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $asset = Asset::factory()->create();
        $base = '/api/v1/assets/'.$asset->id;
        $this->actingAs($admin, 'web')->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        $beforeAsset = $asset->fresh()->getAttributes();
        $beforePeriod = AssetAssignment::query()->sole()->getAttributes();
        $this->travel(1)->hours();
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        $this->assertSame($beforeAsset, $asset->fresh()->getAttributes());
        $this->assertSame($beforePeriod, AssetAssignment::query()->sole()->getAttributes());
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertOk();
        $returned = $asset->fresh()->getAttributes();
        $period = AssetAssignment::query()->sole()->getAttributes();
        $this->travel(1)->hours();
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertOk();
        $this->assertSame($returned, $asset->fresh()->getAttributes());
        $this->assertSame($period, AssetAssignment::query()->sole()->getAttributes());
    }

    public function test_maintenance_preserves_period_allows_same_recipient_and_return_but_no_transfer(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $replacement = User::factory()->create();
        $asset = Asset::factory()->create();
        $base = '/api/v1/assets/'.$asset->id;
        $this->actingAs($admin, 'web')->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        $period = AssetAssignment::query()->sole()->getAttributes();
        $this->patchJson($base.'/status', ['status' => 'maintenance'])->assertOk()->assertJsonPath('data.current_assignment.user.id', $recipient->id);
        $beforeAsset = $asset->fresh()->getAttributes();
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        $this->assertSame($beforeAsset, $asset->fresh()->getAttributes());
        $this->assertSame($period, AssetAssignment::query()->sole()->getAttributes());
        $this->patchJson($base.'/assignment', ['user_id' => $replacement->id])->assertUnprocessable();
        $this->patchJson($base.'/status', ['status' => 'available'])->assertUnprocessable();
        $this->patchJson($base.'/status', ['status' => 'assigned'])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->patchJson($base.'/status', ['status' => 'maintenance'])->assertOk();
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->patchJson($base.'/status', ['status' => 'assigned'])->assertUnprocessable();
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertUnprocessable();
        $this->patchJson($base.'/status', ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'available');
        $this->assertDatabaseCount('asset_assignments', 1);
    }

    public function test_retirement_closes_period_and_is_terminal(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $asset = Asset::factory()->create();
        $base = '/api/v1/assets/'.$asset->id;
        $this->actingAs($admin, 'web')->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        $this->patchJson($base.'/status', ['status' => 'retired'])->assertOk()
            ->assertJsonPath('data.status', 'retired')->assertJsonPath('data.current_assignment', null);
        $this->assertNotNull(AssetAssignment::query()->sole()->ended_at);
        foreach (['available', 'assigned', 'maintenance'] as $status) {
            $this->patchJson($base.'/status', ['status' => $status])->assertUnprocessable();
        }
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertUnprocessable();
        $this->assertDatabaseCount('asset_assignments', 1);
    }

    public function test_direct_assignment_state_is_rejected_and_disabled_recipient_requires_return(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $asset = Asset::factory()->create();
        $base = '/api/v1/assets/'.$asset->id;
        $this->actingAs($admin, 'web')->patchJson($base.'/status', ['status' => 'assigned'])->assertUnprocessable();
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertOk();
        User::query()->whereKey($recipient->id)->update(['is_active' => false]);
        $this->patchJson($base.'/assignment', ['user_id' => $recipient->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->getJson($base)->assertOk()->assertJsonPath('data.current_assignment.user.id', $recipient->id);
        $this->patchJson($base.'/assignment', ['user_id' => null])->assertOk()->assertJsonPath('data.status', 'available');
    }

    public static function invalidAssignments(): array
    {
        return [
            'missing recipient' => [[], 'user_id'], 'nonexistent recipient' => [['user_id' => 999999], 'user_id'],
            'array recipient' => [['user_id' => [1]], 'user_id'], 'noninteger recipient' => [['user_id' => 'bad'], 'user_id'],
            'forged actor' => [['user_id' => null, 'assigned_by' => 1], 'assigned_by'],
            'forged start' => [['user_id' => null, 'started_at' => '2020-01-01'], 'started_at'],
            'forged end' => [['user_id' => null, 'ended_at' => null], 'ended_at'],
        ];
    }

    #[DataProvider('invalidAssignments')]
    public function test_assignment_rejects_invalid_or_server_owned_input(array $payload, string $field): void
    {
        $asset = Asset::factory()->create();
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/assets/'.$asset->id.'/assignment', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public function test_status_rejects_unknown_and_forged_fields(): void
    {
        $asset = Asset::factory()->create();
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => 'deleted'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson('/api/v1/assets/'.$asset->id.'/status', ['status' => 'maintenance', 'user_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertSame('available', $asset->fresh()->status);
    }
}
