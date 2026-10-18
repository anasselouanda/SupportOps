<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTicketRequest extends TicketScopedRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return $this->user()->can('update', $this->visibleTicket())
            && (! $this->exists('priority') || $this->user()->role === User::ROLE_ADMIN);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'min:10', 'max:10000'],
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['sometimes', 'required', 'string', Rule::in(Ticket::PRIORITIES)],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), array_keys($this->rules())) === []) {
                $validator->errors()->add('ticket', 'Au moins un champ modifiable est requis.');
            }
        }];
    }
}
