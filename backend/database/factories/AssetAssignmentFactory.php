<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssetAssignment> */
class AssetAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory()->state(['status' => 'assigned']),
            'user_id' => User::factory(),
            'assigned_by' => User::factory()->admin(),
            'started_at' => now('UTC'),
            'ended_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'asset_id' => Asset::factory(),
            'started_at' => now('UTC')->subDay(),
            'ended_at' => now('UTC'),
        ]);
    }
}
