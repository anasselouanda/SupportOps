<?php

namespace App\Http\Requests\Tickets;

class ListTicketActivityRequest extends TicketScopedRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'integer' => 'Le champ :attribute doit être un nombre entier.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'max.numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['page' => 'page', 'per_page' => 'nombre de résultats par page'];
    }
}
