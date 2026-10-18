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

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->mayActAsRequesterOrAdministrator($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isActiveAdministrator() && $this->view($user, $ticket)->allowed();
    }

    public function start(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)->allowed()
            && ($user->isActiveAdministrator()
                || ($user->role === User::ROLE_TECHNICIAN
                    && (int) $ticket->technician_id === (int) $user->getKey()));
    }

    public function resolve(User $user, Ticket $ticket): bool
    {
        return $this->start($user, $ticket);
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $this->mayActAsRequesterOrAdministrator($user, $ticket);
    }

    public function reopen(User $user, Ticket $ticket): bool
    {
        return $this->mayActAsRequesterOrAdministrator($user, $ticket);
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)->allowed();
    }

    private function mayActAsRequesterOrAdministrator(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)->allowed()
            && ($user->isActiveAdministrator()
                || (int) $ticket->requester_id === (int) $user->getKey());
    }
}
