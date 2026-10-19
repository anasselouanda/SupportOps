<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends AssetRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asset::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'inventory_code' => ['required', 'string', 'max:50', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/', Rule::unique('assets', 'inventory_code')],
            'type' => ['required', 'string', 'max:50', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchase_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
