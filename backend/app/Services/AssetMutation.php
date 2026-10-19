<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssetMutation
{
    /**
     * @template TResult
     *
     * @param  Closure(Asset, User): TResult  $operation
     * @return TResult
     */
    public function run(User $actor, int|string $assetId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $assetId, $operation): mixed {
            $currentActor = $this->currentActor($actor);
            $asset = Asset::query()->visibleTo($currentActor)->lockForUpdate()->findOrFail($assetId);
            Gate::forUser($currentActor)->authorize('view', $asset);

            return $operation($asset, $currentActor);
        }, 5);
    }

    public function authorizeAdministrator(User $actor): User
    {
        $currentActor = $this->currentActor($actor);

        if (! $currentActor->isActiveAdministrator()) {
            throw new AuthorizationException('Accès réservé aux administrateurs actifs.');
        }

        return $currentActor;
    }

    private function currentActor(User $actor): User
    {
        $currentActor = User::query()->lockForUpdate()->find($actor->getKey());

        if ($currentActor === null || ! $currentActor->is_active) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();

            if (request()->hasSession()) {
                request()->session()->invalidate();
                request()->session()->regenerateToken();
            }

            throw new HttpResponseException(response()->json([
                'message' => 'Votre compte a été désactivé. Contactez un administrateur.',
                'code' => 'account_disabled',
            ], 403));
        }

        if (! in_array($currentActor->role, User::ROLES, true)) {
            throw new AuthorizationException('Ce rôle ne permet pas d’accéder au matériel.');
        }

        return $currentActor;
    }
}
