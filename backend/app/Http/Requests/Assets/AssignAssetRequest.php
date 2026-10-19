<?php

namespace App\Http\Requests\Assets;

use Illuminate\Validation\Rule;

class AssignAssetRequest extends AssetScopedRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('assign', $this->visibleAsset());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }
}
