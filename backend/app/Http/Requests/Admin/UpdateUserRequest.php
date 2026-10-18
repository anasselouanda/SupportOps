<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role' => ['sometimes', 'required', 'string', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'string' => 'Le champ :attribute doit être du texte.',
            'email' => 'Le champ :attribute doit contenir une adresse e-mail valide.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'max.numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
            'min.string' => 'Le champ :attribute doit contenir au moins :min caractères.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'unique' => 'Cette adresse e-mail est déjà utilisée.',
            'boolean' => 'Le champ :attribute doit être vrai ou faux.',
            'in' => 'Le rôle sélectionné n’est pas autorisé.',
            'integer' => 'Le champ :attribute doit être un nombre entier.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'email' => 'adresse e-mail',
            'password' => 'mot de passe',
            'password_confirmation' => 'confirmation du mot de passe',
            'role' => 'rôle',
            'is_active' => 'activation',
            'page' => 'page',
            'per_page' => 'nombre de résultats par page',
        ];
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
