<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssetWorkflowService
{
    public function __construct(private AssetMutation $mutation) {}

    public function assign(User $actor, int|string $assetId, ?int $userId): Asset
    {
        return $this->mutation->run($actor, $assetId, function (Asset $asset, User $currentActor) use ($userId): Asset {
            Gate::forUser($currentActor)->authorize('assign', $asset);
            $currentAssignment = $this->currentAssignment($asset);

            if ($userId !== null) {
                $recipient = User::query()->lockForUpdate()->find($userId);

                if ($recipient === null || ! $recipient->is_active) {
                    throw ValidationException::withMessages(['user_id' => ['Sélectionnez un utilisateur actif.']]);
                }
            }

            if ($currentAssignment?->user_id === $userId) {
                return $asset;
            }

            if ($userId !== null && in_array($asset->status, ['maintenance', 'retired'], true)) {
                throw ValidationException::withMessages(['user_id' => ['Un matériel en maintenance ou retiré ne peut pas être affecté ou transféré.']]);
            }

            $occurredAt = now('UTC');

            if ($currentAssignment !== null) {
                $currentAssignment->ended_at = $occurredAt;
                $currentAssignment->save();
            }

            if ($userId !== null) {
                $asset->assignments()->create([
                    'user_id' => $userId,
                    'assigned_by' => $currentActor->getKey(),
                    'started_at' => $occurredAt,
                    'ended_at' => null,
                ]);
            }

            if ($asset->status !== 'maintenance') {
                $asset->status = $userId === null ? 'available' : 'assigned';
            }

            $asset->save();

            return $asset;
        });
    }

    public function changeStatus(User $actor, int|string $assetId, string $status): Asset
    {
        return $this->mutation->run($actor, $assetId, function (Asset $asset, User $currentActor) use ($status): Asset {
            Gate::forUser($currentActor)->authorize('changeStatus', $asset);

            if (! in_array($status, Asset::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ['Le statut sélectionné n’est pas autorisé.']]);
            }

            if ($asset->status === $status) {
                return $asset;
            }

            if ($asset->status === 'retired') {
                throw ValidationException::withMessages(['status' => ['Un matériel retiré ne peut plus changer d’état.']]);
            }

            $currentAssignment = $this->currentAssignment($asset);

            if (in_array($status, ['available', 'assigned'], true)) {
                $effectiveStatus = $currentAssignment === null ? 'available' : 'assigned';

                if ($status !== $effectiveStatus) {
                    throw ValidationException::withMessages(['status' => ['L’état doit correspondre à l’affectation courante. Utilisez l’action d’affectation ou de restitution.']]);
                }
            }

            if ($status === 'retired' && $currentAssignment !== null) {
                $currentAssignment->ended_at = now('UTC');
                $currentAssignment->save();
            }

            $asset->status = $status;
            $asset->save();

            return $asset;
        });
    }

    private function currentAssignment(Asset $asset): ?AssetAssignment
    {
        return $asset->assignments()->whereNull('ended_at')->lockForUpdate()->first();
    }
}
