<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TicketAssetService
{
    /** Called only when asset_id is supplied, inside the ticket transaction. */
    public function resolve(User $actor, ?int $assetId): ?Asset
    {
        if ($assetId === null) {
            return null;
        }

        $asset = Asset::query()->lockForUpdate()->find($assetId);

        if ($asset !== null && $actor->isActiveAdministrator()) {
            return $asset;
        }

        $assignment = $asset === null ? null : AssetAssignment::query()
            ->where('asset_id', $asset->getKey())->whereNull('ended_at')->lockForUpdate()->first();

        if ($asset === null || $assignment === null || (int) $assignment->user_id !== (int) $actor->getKey()
            || ! in_array($asset->status, ['assigned', 'maintenance'], true)) {
            throw ValidationException::withMessages(['asset_id' => ['Le matériel sélectionné est indisponible pour cette demande.']]);
        }

        return $asset;
    }
}
