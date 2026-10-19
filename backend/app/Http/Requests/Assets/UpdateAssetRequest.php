<?php

namespace App\Http\Requests\Assets;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAssetRequest extends AssetScopedRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('update', $this->visibleAsset());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'inventory_code' => ['sometimes', 'required', 'string', 'max:50', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/', Rule::unique('assets', 'inventory_code')->ignore($this->visibleAsset())],
            'type' => ['sometimes', 'required', 'string', 'max:50', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'brand' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), array_keys($this->rules())) === []) {
                $validator->errors()->add('asset', 'Au moins un champ modifiable est requis.');
            }
        }];
    }
}
