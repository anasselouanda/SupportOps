<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Support\Str;

class StoreTicketCommentRequest extends TicketScopedRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => Str::trim($this->input('content'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['content' => ['required', 'string', 'min:1', 'max:5000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content.required' => 'Le commentaire ne peut pas être vide.',
            'content.string' => 'Le commentaire doit être du texte.',
            'content.min' => 'Le commentaire doit contenir au moins :min caractère.',
            'content.max' => 'Le commentaire ne doit pas dépasser :max caractères.',
        ];
    }
}
