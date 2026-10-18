<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class TicketScopedRequest extends FormRequest
{
    private ?Ticket $resolvedTicket = null;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        Gate::authorize('view', $this->visibleTicket());

        return true;
    }

    protected function visibleTicket(): Ticket
    {
        return $this->resolvedTicket ??= Ticket::query()->visibleTo($this->user())
            ->findOrFail($this->route('ticket'));
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Ce champ n’est pas autorisé.');
            }
        }];
    }
}
