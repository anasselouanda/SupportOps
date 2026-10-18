<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, User::ROLES, true);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Ticket $ticket): Response
    {
        return Ticket::query()->visibleTo($user)->whereKey($ticket->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
