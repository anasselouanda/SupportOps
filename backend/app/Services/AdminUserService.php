<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminUserService
{
    /** @param array{name: string, email: string, password: string, role: string, is_active?: bool} $attributes */
    public function create(User $actor, array $attributes): User
    {
        return $this->withAdministrationLock(function () use ($actor, $attributes): User {
            $this->authorizeCurrentAdministrator($actor);
            $this->ensureEmailAvailable($attributes['email']);

            return User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'role' => $attributes['role'],
                'is_active' => $attributes['is_active'] ?? true,
            ]);
        });
    }

    /** @param array{name?: string, email?: string, role?: string, is_active?: bool} $attributes */
    public function update(User $actor, User $target, array $attributes): User
    {
        return $this->withAdministrationLock(function () use ($actor, $target, $attributes): User {
            $this->authorizeCurrentAdministrator($actor);
            $activeAdministrators = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $user = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (array_key_exists('email', $attributes)) {
                $this->ensureEmailAvailable($attributes['email'], $user->getKey());
            }

            $user->fill($attributes);

            if ($activeAdministrators->contains('id', $user->getKey())
                && $activeAdministrators->count() === 1
                && ! $user->isActiveAdministrator()) {
                $field = $user->is_active ? 'role' : 'is_active';

                throw ValidationException::withMessages([
                    $field => ['Le dernier administrateur actif doit conserver son rôle et son activation.'],
                ]);
            }

            $user->save();

            return $user;
        });
    }

    /** @param array{name: string, email: string, password: string} $attributes */
    public function createInitialAdministrator(array $attributes): User
    {
        return $this->withAdministrationLock(function () use ($attributes): User {
            if (User::query()->where('role', User::ROLE_ADMIN)->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages([
                    'role' => ['Un administrateur existe déjà. Utilisez la gestion des utilisateurs.'],
                ]);
            }

            $this->ensureEmailAvailable($attributes['email']);

            return User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Serialize every account write and the initial administrator bootstrap.
     * Locking reads obtain current MySQL values even under REPEATABLE READ.
     *
     * @param  Closure(): User  $operation
     */
    private function withAdministrationLock(Closure $operation): User
    {
        return DB::transaction(function () use ($operation): User {
            $lock = DB::table('user_administration_locks')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if ($lock === null) {
                throw new RuntimeException('Le verrou de gestion des utilisateurs est absent. Appliquez les migrations.');
            }

            return $operation();
        }, 5);
    }

    private function authorizeCurrentAdministrator(User $actor): void
    {
        $currentActor = User::query()->lockForUpdate()->find($actor->getKey());

        if ($currentActor === null || ! $currentActor->isActiveAdministrator()) {
            throw new AuthorizationException('Accès réservé aux administrateurs actifs.');
        }
    }

    private function ensureEmailAvailable(string $email, ?int $exceptUserId = null): void
    {
        $query = User::query()->where('email', $email);

        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        if ($query->lockForUpdate()->first() !== null) {
            throw ValidationException::withMessages([
                'email' => ['Cette adresse e-mail est déjà utilisée.'],
            ]);
        }
    }
}
