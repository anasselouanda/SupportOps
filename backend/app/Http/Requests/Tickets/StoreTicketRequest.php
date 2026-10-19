<?php

namespace App\Http\Requests\Tickets;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['sometimes', 'string', Rule::in(Ticket::PRIORITIES)],
            'asset_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'string' => 'Le champ :attribute doit être du texte.',
            'integer' => 'Le champ :attribute doit être un nombre entier.',
            'min.string' => 'Le champ :attribute doit contenir au moins :min caractères.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'category_id.exists' => 'La catégorie sélectionnée est indisponible.',
            'priority.in' => 'La priorité sélectionnée n’est pas autorisée.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => 'titre', 'description' => 'description', 'category_id' => 'catégorie', 'priority' => 'priorité'];
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
