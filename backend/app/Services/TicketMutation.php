<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TicketMutation
{
    /**
     * @template TResult
     *
     * @param  Closure(Ticket, User): TResult  $operation
     * @return TResult
     */
    public function run(User $actor, int|string $ticketId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $ticketId, $operation): mixed {
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

            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticketId);
            Gate::forUser($currentActor)->authorize('view', $ticket);

            return $operation($ticket, $currentActor);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Ticket $ticket, User $actor, string $type, array $before, array $after): TicketEvent
    {
        return $ticket->events()->create([
            'actor_id' => $actor->getKey(),
            'type' => $type,
            'occurred_at' => now('UTC'),
            'metadata' => ['before' => $before, 'after' => $after],
        ]);
    }
}
