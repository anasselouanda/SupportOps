<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use Illuminate\Validation\Rule;

class ListAssetsRequest extends AssetRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Asset::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:50', 'regex:/\A[a-z][a-z0-9_-]*\z/'],
            'status' => ['sometimes', 'string', Rule::in(Asset::STATUSES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(['inventory_code', 'type', 'brand', 'model', 'status', 'created_at', 'id'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
