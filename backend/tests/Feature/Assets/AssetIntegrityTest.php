<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use App\Services\AssetInventoryService;
use App\Services\AssetWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AssetIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public static function failedMutations(): array
    {
        return ['assignment insert' => ['assignment', 'assignment_creating'], 'transfer insert' => ['transfer', 'assignment_creating'],
            'return close' => ['return', 'assignment_updating'], 'retirement close' => ['retire', 'assignment_updating'],
            'assignment state' => ['assignment', 'asset_updating'], 'transfer state' => ['transfer', 'asset_saving']];
    }

    #[DataProvider('failedMutations')]
    public function test_model_event_failure_rolls_back_state_and_complete_history(string $action, string $failure): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $asset = Asset::factory()->create();
        if ($action !== 'assignment') {
            app(AssetWorkflowService::class)->assign($admin, $asset->id, $recipient->id);
        }
        $beforeAsset = $asset->fresh()->getAttributes();
        $beforePeriods = AssetAssignment::query()->orderBy('id')->get()->map->getAttributes()->all();
        $replacement = User::factory()->create();
        $dispatcher = Asset::getEventDispatcher();
        Asset::setEventDispatcher(clone $dispatcher);
        [$target, $eventName] = explode('_', $failure);
        $model = $target === 'asset' ? Asset::class : AssetAssignment::class;
        $model::{$eventName}(function (): void {
            throw new RuntimeException('Simulated asset write failure.');
        });

        try {
            $base = '/api/v1/assets/'.$asset->id;
            $this->actingAs($admin, 'web')->patchJson($base.($action === 'retire' ? '/status' : '/assignment'),
                $action === 'retire' ? ['status' => 'retired'] : ['user_id' => $action === 'return' ? null : $replacement->id])->assertStatus(500);
            $this->assertSame($beforeAsset, $asset->fresh()->getAttributes());
            $this->assertSame($beforePeriods, AssetAssignment::query()->orderBy('id')->get()->map->getAttributes()->all());
        } finally {
            Asset::setEventDispatcher($dispatcher);
        }
    }

    public function test_database_rejects_two_active_periods_but_accepts_closed_history(): void
    {
        $asset = Asset::factory()->create();
        $attributes = AssetAssignment::factory()->make(['asset_id' => $asset->id])->getAttributes();
        unset($attributes['id']);
        DB::table('asset_assignments')->insert($attributes);
        $closed = $attributes;
        $closed['ended_at'] = now();
        DB::table('asset_assignments')->insert($closed);
        $this->assertDatabaseCount('asset_assignments', 2);

        try {
            DB::table('asset_assignments')->insert($attributes);
            $this->fail('A database unique constraint must protect the active assignment.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseCount('asset_assignments', 2);
    }

    public function test_historical_asset_recipient_and_actor_cannot_be_deleted(): void
    {
        $asset = Asset::factory()->create();
        $recipient = User::factory()->create();
        $actor = User::factory()->admin()->create();
        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'user_id' => $recipient->id, 'assigned_by' => $actor->id, 'ended_at' => now()]);

        foreach ([['assets', $asset->id], ['users', $recipient->id], ['users', $actor->id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Historical references must use restrictive foreign keys.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
        $this->assertDatabaseCount('asset_assignments', 1);
    }

    public static function staleActorOperations(): array
    {
        return ['inventory create' => ['create'], 'metadata update' => ['update'], 'assignment' => ['assign'], 'status' => ['status']];
    }

    private function mutate(User $actor, Asset $asset, string $operation): void
    {
        match ($operation) {
            'create' => app(AssetInventoryService::class)->create($actor, ['inventory_code' => 'NEW-001', 'type' => 'laptop', 'brand' => 'Brand', 'model' => 'Model']),
            'update' => app(AssetInventoryService::class)->update($actor, $asset->id, ['brand' => 'Changed']),
            'assign' => app(AssetWorkflowService::class)->assign($actor, $asset->id, null),
            'status' => app(AssetWorkflowService::class)->changeStatus($actor, $asset->id, 'maintenance'),
        };
    }

    #[DataProvider('staleActorOperations')]
    public function test_services_reload_demoted_actor_before_any_write(string $operation): void
    {
        $actor = User::factory()->admin()->create();
        $asset = Asset::factory()->create();
        $before = $asset->fresh()->getAttributes();
        User::query()->whereKey($actor->id)->update(['role' => 'employee']);
        try {
            $this->mutate($actor, $asset, $operation);
            $this->fail('The current database role must replace a stale administrator role.');
        } catch (AuthorizationException|ModelNotFoundException $exception) {
            if ($exception instanceof AuthorizationException) {
                $this->assertContains($exception->status() ?? 403, [403, 404]);
            } else {
                $this->assertSame(Asset::class, $exception->getModel());
            }
        }
        $this->assertSame($before, $asset->fresh()->getAttributes());
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    #[DataProvider('staleActorOperations')]
    public function test_services_reload_disabled_actor_and_invalidate_session(string $operation): void
    {
        $actor = User::factory()->admin()->create();
        $asset = Asset::factory()->create();
        $before = $asset->fresh()->getAttributes();
        $this->actingAs($actor, 'web');
        User::query()->whereKey($actor->id)->update(['is_active' => false]);
        try {
            $this->mutate($actor, $asset, $operation);
            $this->fail('A stale active account must not mutate assets.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('account_disabled', $exception->getResponse()->getData(true)['code']);
        }
        $this->assertGuest('web');
        $this->assertSame($before, $asset->fresh()->getAttributes());
        $this->assertDatabaseCount('assets', 1);
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public function test_assignment_service_reloads_recipient_activation(): void
    {
        $admin = User::factory()->admin()->create();
        $recipient = User::factory()->create();
        $asset = Asset::factory()->create();
        User::query()->whereKey($recipient->id)->update(['is_active' => false]);
        try {
            app(AssetWorkflowService::class)->assign($admin, $asset->id, $recipient->id);
            $this->fail('A recipient disabled after selection must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user_id', $exception->errors());
        }
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertDatabaseCount('asset_assignments', 0);
    }
}
