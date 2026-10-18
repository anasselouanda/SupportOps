<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListTicketsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Ticket::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(Ticket::STATUSES)],
            'priority' => ['sometimes', 'string', Rule::in(Ticket::PRIORITIES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'string' => 'Le champ :attribute doit être du texte.',
            'integer' => 'Le champ :attribute doit être un nombre entier.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'max.numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'status.in' => 'Le statut sélectionné n’est pas autorisé.',
            'priority.in' => 'La priorité sélectionnée n’est pas autorisée.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['search' => 'recherche', 'status' => 'statut', 'priority' => 'priorité', 'page' => 'page', 'per_page' => 'nombre de résultats par page'];
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
