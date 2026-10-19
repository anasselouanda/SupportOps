<?php

namespace App\Http\Requests\Assets;

class ListAssetAssignmentsRequest extends AssetScopedRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('viewHistory', $this->visibleAsset());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
