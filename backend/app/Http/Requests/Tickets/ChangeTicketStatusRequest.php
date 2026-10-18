<?php

namespace App\Http\Requests\Tickets;

use Closure;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends TicketScopedRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $action = match ($this->input('status')) {
            'in_progress' => 'start',
            'resolved' => 'resolve',
            'closed' => 'close',
            'open' => 'reopen',
            default => null,
        };

        return $action === null || $this->user()->can($action, $this->visibleTicket());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
            'resolution_note' => ['missing_unless:status,resolved', 'required_if:status,resolved', 'string', 'max:10000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/[^\s\p{Z}]/u', $value) !== 1) {
                    $fail('La note de résolution doit contenir du texte.');
                }
            }],
        ];
    }
}
