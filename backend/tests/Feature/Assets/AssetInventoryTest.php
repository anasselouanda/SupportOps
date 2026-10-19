<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace(['inventory_code' => 'PC-001', 'type' => 'laptop', 'brand' => 'Lenovo', 'model' => 'ThinkPad'], $overrides);
    }

    public function test_admin_creates_canonical_inventory_and_edits_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/assets', $this->payload([
            'inventory_code' => 'pc-001', 'serial_number' => 'SN-17', 'purchase_date' => '2025-09-15',
        ]))->assertCreated()->assertJsonPath('data.inventory_code', 'PC-001')->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.current_assignment', null);
        $asset = Asset::query()->sole();
        $this->assertEqualsCanonicalizing(['id', 'inventory_code', 'type', 'brand', 'model', 'serial_number', 'status', 'purchase_date', 'created_at', 'updated_at', 'current_assignment'], array_keys($response->json('data')));
        $this->patchJson('/api/v1/assets/'.$asset->id, ['brand' => 'Dell', 'serial_number' => null, 'purchase_date' => null])
            ->assertOk()->assertJsonPath('data.brand', 'Dell')->assertJsonPath('data.serial_number', null)->assertJsonPath('data.purchase_date', null);
        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public static function invalidMetadata(): array
    {
        return [
            'empty code' => [['inventory_code' => ''], 'inventory_code'],
            'long code' => [['inventory_code' => str_repeat('A', 51)], 'inventory_code'],
            'invalid code' => [['inventory_code' => 'PC 001'], 'inventory_code'],
            'invalid first character' => [['inventory_code' => '-PC'], 'inventory_code'],
            'invalid type' => [['type' => 'Laptop computer'], 'type'],
            'long type' => [['type' => str_repeat('a', 51)], 'type'],
            'missing brand' => [['brand' => null], 'brand'],
            'long brand' => [['brand' => str_repeat('a', 101)], 'brand'],
            'missing model' => [['model' => ''], 'model'],
            'long model' => [['model' => str_repeat('a', 101)], 'model'],
            'long serial' => [['serial_number' => str_repeat('a', 101)], 'serial_number'],
            'invalid date' => [['purchase_date' => 'yesterday-ish'], 'purchase_date'],
            'forged state' => [['status' => 'assigned'], 'status'],
            'forged recipient' => [['user_id' => null], 'user_id'],
            'forged active assignment' => [['current_assignment' => null], 'current_assignment'],
            'forged actor' => [['assigned_by' => 1], 'assigned_by'],
            'forged history' => [['assignments' => []], 'assignments'],
            'forged timestamp' => [['created_at' => null], 'created_at'],
            'unknown field' => [['unexpected' => null], 'unexpected'],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_create_and_update_reject_invalid_or_server_owned_fields(array $overrides, string $field): void
    {
        $admin = User::factory()->admin()->create();
        $asset = Asset::factory()->create();
        $before = $asset->fresh()->getAttributes();
        $this->actingAs($admin, 'web')->postJson('/api/v1/assets', $this->payload($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/v1/assets/'.$asset->id, $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('assets', 1);
        $this->assertSame($before, $asset->fresh()->getAttributes());
    }

    public function test_code_uniqueness_uses_canonical_value_and_self_update_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $asset = Asset::factory()->create(['inventory_code' => 'PC-001']);
        $other = Asset::factory()->create(['inventory_code' => 'PC-002']);
        $this->actingAs($admin, 'web')->postJson('/api/v1/assets', $this->payload(['inventory_code' => 'pc-001']))
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_code');
        $this->patchJson('/api/v1/assets/'.$other->id, ['inventory_code' => 'pc-001'])
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_code');
        $this->patchJson('/api/v1/assets/'.$asset->id, ['inventory_code' => 'pc-001'])->assertOk();
        $this->patchJson('/api/v1/assets/'.$asset->id, [])->assertUnprocessable();
        $this->assertDatabaseCount('assets', 2);
    }

    public static function invalidQueries(): array
    {
        return [
            'long search' => [['search' => str_repeat('a', 256)], 'search'], 'array search' => [['search' => ['a']], 'search'],
            'invalid type' => [['type' => 'bad type'], 'type'], 'invalid state' => [['status' => 'deleted'], 'status'],
            'zero page' => [['page' => 0], 'page'], 'bad page' => [['page' => 'abc'], 'page'],
            'zero size' => [['per_page' => 0], 'per_page'], 'oversized page' => [['per_page' => 101], 'per_page'],
            'invalid sort' => [['sort' => 'user_id'], 'sort'], 'invalid direction' => [['direction' => 'sideways'], 'direction'],
            'forged user scope' => [['user_id' => 1], 'user_id'], 'unknown parameter' => [['unexpected' => 'value'], 'unexpected'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_filters_sort_and_pagination_are_rejected(array $query, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/v1/assets?'.http_build_query($query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function literalSearches(): array
    {
        return ['percent' => ['%'], 'underscore' => ['_'], 'escape' => ['!']];
    }

    #[DataProvider('literalSearches')]
    public function test_search_is_literal_and_scoped_before_filtering(string $search): void
    {
        $employee = User::factory()->create();
        $match = Asset::factory()->create(['status' => 'assigned', 'model' => 'Model '.$search, 'inventory_code' => 'PC001']);
        AssetAssignment::factory()->create(['asset_id' => $match->id, 'user_id' => $employee->id]);
        $ordinary = Asset::factory()->create(['status' => 'assigned', 'model' => 'Ordinary', 'inventory_code' => 'PC002', 'brand' => 'Ordinary', 'serial_number' => null]);
        AssetAssignment::factory()->create(['asset_id' => $ordinary->id, 'user_id' => $employee->id]);
        Asset::factory()->create(['model' => 'Private '.$search]);
        $this->actingAs($employee, 'web')->getJson('/api/v1/assets?'.http_build_query(['search' => $search]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    }

    public function test_filters_and_stable_pagination_are_applied_on_server(): void
    {
        $admin = User::factory()->admin()->create();
        $assets = Asset::factory()->count(18)->create(['created_at' => now()->subDay(), 'type' => 'laptop', 'status' => 'available']);
        Asset::factory()->create(['type' => 'printer', 'status' => 'maintenance']);
        $first = $this->actingAs($admin, 'web')->getJson('/api/v1/assets?type=laptop&status=available')->assertOk()
            ->assertJsonPath('meta.total', 18)->assertJsonPath('meta.per_page', 15)->assertJsonCount(15, 'data');
        $second = $this->getJson('/api/v1/assets?type=laptop&status=available&page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame($assets->pluck('id')->reverse()->values()->all(), [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')]);
        $this->getJson('/api/v1/assets?sort=id&direction=asc&per_page=100')->assertOk()->assertJsonPath('data.0.id', $assets->first()->id);
    }
}
