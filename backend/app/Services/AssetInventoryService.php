<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssetInventoryService
{
    public function __construct(private AssetMutation $mutation) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Asset
    {
        try {
            return DB::transaction(function () use ($actor, $attributes): Asset {
                $administrator = $this->mutation->authorizeAdministrator($actor);
                Gate::forUser($administrator)->authorize('create', Asset::class);

                return Asset::query()->create([...$this->metadata($attributes), 'status' => 'available']);
            }, 5);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['inventory_code' => ['Ce code inventaire est déjà utilisé.']]);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, int|string $assetId, array $attributes): Asset
    {
        try {
            return $this->mutation->run($actor, $assetId, function (Asset $asset, User $administrator) use ($attributes): Asset {
                Gate::forUser($administrator)->authorize('update', $asset);
                $asset->fill($this->metadata($attributes));
                $asset->save();

                return $asset;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['inventory_code' => ['Ce code inventaire est déjà utilisé.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function metadata(array $attributes): array
    {
        $metadata = Arr::only($attributes, Asset::METADATA_FIELDS);

        if (isset($metadata['inventory_code'])) {
            $metadata['inventory_code'] = strtoupper(trim($metadata['inventory_code']));
        }

        return $metadata;
    }
}
