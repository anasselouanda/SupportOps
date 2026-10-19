<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssetRequest extends FormRequest
{
    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Ce champ n’est pas autorisé.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'present' => 'Le champ :attribute doit être transmis.',
            'string' => 'Le champ :attribute doit être du texte.',
            'integer' => 'Le champ :attribute doit être un nombre entier.',
            'max.string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
            'max.numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
            'min.numeric' => 'Le champ :attribute doit être au moins égal à :min.',
            'inventory_code.regex' => 'Utilisez uniquement des lettres, chiffres, points, tirets et traits de soulignement, en commençant par une lettre ou un chiffre.',
            'inventory_code.unique' => 'Ce code inventaire est déjà utilisé.',
            'type.regex' => 'Le type doit commencer par une lettre minuscule et contenir uniquement des lettres minuscules, chiffres, tirets ou traits de soulignement.',
            'purchase_date.date_format' => 'La date d’achat doit être une date valide au format AAAA-MM-JJ.',
            'status.in' => 'Le statut sélectionné n’est pas autorisé.',
            'sort.in' => 'Le tri sélectionné n’est pas autorisé.',
            'direction.in' => 'Le sens du tri doit être asc ou desc.',
            'user_id.exists' => 'Le bénéficiaire sélectionné est indisponible.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'inventory_code' => 'code inventaire', 'type' => 'type', 'brand' => 'marque',
            'model' => 'modèle', 'serial_number' => 'numéro de série', 'purchase_date' => 'date d’achat',
            'status' => 'statut', 'user_id' => 'bénéficiaire', 'search' => 'recherche',
            'page' => 'page', 'per_page' => 'nombre de résultats par page', 'sort' => 'tri', 'direction' => 'sens du tri',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('inventory_code') && is_string($this->input('inventory_code'))) {
            $this->merge(['inventory_code' => strtoupper(trim($this->input('inventory_code')))]);
        }
    }
}
