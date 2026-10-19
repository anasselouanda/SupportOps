<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketService
{
    /** @param array{title: string, description: string, category_id: int, priority?: string, asset_id?: ?int} $attributes */
    public function create(User $actor, array $attributes): Ticket
    {
        return DB::transaction(function () use ($actor, $attributes): Ticket {
            $requester = User::query()->lockForUpdate()->find($actor->getKey());

            if ($requester === null || ! $requester->is_active) {
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

            if (! in_array($requester->role, User::ROLES, true)) {
                throw new AuthorizationException('Un compte actif est requis pour créer un ticket.');
            }

            $category = Category::query()->whereKey($attributes['category_id'])->where('is_active', true)->lockForUpdate()->first();

            if ($category === null) {
                throw ValidationException::withMessages(['category_id' => ['La catégorie sélectionnée est indisponible.']]);
            }

            $asset = array_key_exists('asset_id', $attributes)
                ? app(TicketAssetService::class)->resolve($requester, $attributes['asset_id'] === null ? null : (int) $attributes['asset_id']) : null;

            $ticket = Ticket::query()->create([
                'reference' => 'TKT-'.Str::ulid(),
                'requester_id' => $requester->getKey(),
                'technician_id' => null,
                'category_id' => $category->getKey(),
                'asset_id' => $asset?->getKey(),
                'title' => $attributes['title'],
                'description' => $attributes['description'],
                'priority' => $attributes['priority'] ?? 'normal',
                'status' => 'open',
            ]);

            $ticket->events()->create([
                'actor_id' => $requester->getKey(),
                'type' => 'created',
                'occurred_at' => now(),
                'metadata' => ['status' => $ticket->status, 'priority' => $ticket->priority, 'category_id' => $category->getKey(),
                    ...($asset === null ? [] : ['asset_id' => $asset->getKey()])],
            ]);

            return $ticket;
        }, 5);
    }
}
