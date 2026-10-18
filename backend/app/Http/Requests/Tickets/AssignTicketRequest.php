<?php

namespace App\Http\Requests\Tickets;

use App\Models\User;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends TicketScopedRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('assign', $this->visibleTicket());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'technician_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('role', User::ROLE_TECHNICIAN)],
        ];
    }
}
