<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, User::ROLES, true);
    }

    public function view(User $user, Asset $asset): Response
    {
        return Asset::query()->visibleTo($user)->whereKey($asset->getKey())->exists()
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isActiveAdministrator();
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->isActiveAdministrator() && $this->view($user, $asset)->allowed();
    }

    public function assign(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset);
    }

    public function changeStatus(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset);
    }

    public function viewHistory(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset);
    }
}
