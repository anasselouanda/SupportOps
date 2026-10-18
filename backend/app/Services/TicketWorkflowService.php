<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketWorkflowService
{
    public function __construct(private TicketMutation $mutation) {}

    /** @param array{title?: string, description?: string, category_id?: int, priority?: string} $attributes */
    public function update(User $actor, int|string $ticketId, array $attributes): Ticket
    {
        return $this->mutation->run($actor, $ticketId, function (Ticket $ticket, User $currentActor) use ($attributes): Ticket {
            Gate::forUser($currentActor)->authorize('update', $ticket);

            if (array_key_exists('priority', $attributes) && $currentActor->role !== User::ROLE_ADMIN) {
                throw new AuthorizationException('Seul un administrateur peut modifier la priorité.');
            }

            if ($ticket->status === 'closed') {
                throw ValidationException::withMessages(['ticket' => ['Un ticket fermé ne peut plus être modifié.']]);
            }

            if ($currentActor->role !== User::ROLE_ADMIN && ($ticket->status !== 'open' || $ticket->technician_id !== null)) {
                throw ValidationException::withMessages(['ticket' => ['Le demandeur peut modifier uniquement un ticket ouvert et non affecté.']]);
            }

            $rules = [
                'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
                'description' => ['sometimes', 'required', 'string', 'min:10', 'max:10000'],
                'category_id' => ['sometimes', 'required', 'integer'],
                'priority' => ['sometimes', 'required', 'string', Rule::in(Ticket::PRIORITIES)],
            ];
            $unknownFields = array_diff(array_keys($attributes), array_keys($rules));

            if ($unknownFields !== []) {
                throw ValidationException::withMessages(array_fill_keys($unknownFields, ['Ce champ n’est pas autorisé.']));
            }

            if ($attributes === []) {
                throw ValidationException::withMessages(['ticket' => ['Au moins un champ modifiable est requis.']]);
            }

            Validator::make($attributes, $rules)->validate();

            if (array_key_exists('category_id', $attributes)) {
                $category = Category::query()->whereKey($attributes['category_id'])->where('is_active', true)->lockForUpdate()->first();

                if ($category === null) {
                    throw ValidationException::withMessages(['category_id' => ['La catégorie sélectionnée est indisponible.']]);
                }

                $attributes['category_id'] = $category->getKey();
            }

            $before = [];
            $after = [];

            foreach (['title', 'description', 'category_id'] as $field) {
                if (array_key_exists($field, $attributes) && (string) $ticket->{$field} !== (string) $attributes[$field]) {
                    $before[$field] = $ticket->{$field};
                    $after[$field] = $attributes[$field];
                }
            }

            $priorityChanged = array_key_exists('priority', $attributes) && $ticket->priority !== $attributes['priority'];
            $previousPriority = $ticket->priority;

            if ($after === [] && ! $priorityChanged) {
                return $ticket;
            }

            $ticket->fill($after);

            if ($priorityChanged) {
                $ticket->priority = $attributes['priority'];
            }

            $ticket->save();

            if ($after !== []) {
                $this->mutation->record($ticket, $currentActor, 'updated', $before, $after);
            }

            if ($priorityChanged) {
                $this->mutation->record($ticket, $currentActor, 'priority_changed', ['priority' => $previousPriority], ['priority' => $ticket->priority]);
            }

            return $ticket;
        });
    }

    public function assign(User $actor, int|string $ticketId, ?int $technicianId): Ticket
    {
        return $this->mutation->run($actor, $ticketId, function (Ticket $ticket, User $currentActor) use ($technicianId): Ticket {
            Gate::forUser($currentActor)->authorize('assign', $ticket);

            if (! in_array($ticket->status, ['open', 'in_progress'], true)) {
                throw ValidationException::withMessages(['ticket' => ['L’affectation est possible uniquement pour un ticket ouvert ou en cours.']]);
            }

            if ($technicianId === null && $ticket->status !== 'open') {
                throw ValidationException::withMessages(['technician_id' => ['Seul un ticket ouvert peut être désaffecté.']]);
            }

            if ($technicianId !== null) {
                $technician = User::query()->whereKey($technicianId)->lockForUpdate()->first();

                if ($technician === null || ! $technician->is_active || $technician->role !== User::ROLE_TECHNICIAN) {
                    throw ValidationException::withMessages(['technician_id' => ['Sélectionnez un technicien actif.']]);
                }
            }

            if ($ticket->technician_id === $technicianId) {
                return $ticket;
            }

            $before = ['technician_id' => $ticket->technician_id];
            $ticket->technician_id = $technicianId;
            $ticket->save();
            $this->mutation->record($ticket, $currentActor, 'assignment_changed', $before, ['technician_id' => $technicianId]);

            return $ticket;
        });
    }

    public function changeStatus(User $actor, int|string $ticketId, string $status, ?string $resolutionNote = null): Ticket
    {
        return $this->mutation->run($actor, $ticketId, function (Ticket $ticket, User $currentActor) use ($status, $resolutionNote): Ticket {
            $action = match ($status) {
                'in_progress' => 'start',
                'resolved' => 'resolve',
                'closed' => 'close',
                'open' => 'reopen',
                default => null,
            };

            if ($action === null) {
                throw ValidationException::withMessages(['status' => ['Le statut sélectionné n’est pas autorisé.']]);
            }

            Gate::forUser($currentActor)->authorize($action, $ticket);
            $sourceStatus = match ($status) {
                'in_progress' => 'open',
                'resolved' => 'in_progress',
                'closed', 'open' => 'resolved',
            };

            if ($ticket->status !== $sourceStatus) {
                throw ValidationException::withMessages(['status' => ['Cette transition de statut n’est pas autorisée.']]);
            }

            if ($status === 'resolved') {
                Validator::make(['resolution_note' => $resolutionNote], ['resolution_note' => ['required', 'string', 'max:10000']])->validate();

                if (preg_match('/[^\s\p{Z}]/u', $resolutionNote) !== 1) {
                    throw ValidationException::withMessages(['resolution_note' => ['La note de résolution doit contenir du texte.']]);
                }
            } elseif ($resolutionNote !== null) {
                throw ValidationException::withMessages(['resolution_note' => ['La note de résolution est réservée à la résolution.']]);
            }

            if ($status === 'in_progress') {
                $technician = $ticket->technician_id === null ? null : User::query()->whereKey($ticket->technician_id)->lockForUpdate()->first();

                if ($technician === null || ! $technician->is_active || $technician->role !== User::ROLE_TECHNICIAN) {
                    throw ValidationException::withMessages(['technician_id' => ['Un technicien actif doit être affecté avant le démarrage.']]);
                }
            }

            $before = $this->statusSnapshot($ticket);
            $ticket->status = $status;

            if ($status === 'resolved') {
                $ticket->resolved_at = now('UTC');
                $ticket->resolution_note = $resolutionNote;
            } elseif ($status === 'closed') {
                $ticket->closed_at = now('UTC');
            } elseif ($status === 'open') {
                $ticket->resolved_at = null;
                $ticket->resolution_note = null;
                $ticket->closed_at = null;
            }

            $ticket->save();
            $eventType = match ($status) {
                'resolved' => 'resolved',
                'closed' => 'closed',
                'open' => 'reopened',
                'in_progress' => 'status_changed',
            };
            $this->mutation->record($ticket, $currentActor, $eventType, $before, $this->statusSnapshot($ticket));

            return $ticket;
        });
    }

    /** @return array{status: string, resolution_note: ?string, resolved_at: ?string, closed_at: ?string} */
    private function statusSnapshot(Ticket $ticket): array
    {
        return [
            'status' => $ticket->status,
            'resolution_note' => $ticket->resolution_note,
            'resolved_at' => $ticket->resolved_at?->utc()->toISOString(),
            'closed_at' => $ticket->closed_at?->utc()->toISOString(),
        ];
    }
}
