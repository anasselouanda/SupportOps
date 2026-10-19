<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use Illuminate\Validation\Rule;

class ChangeAssetStatusRequest extends AssetScopedRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('changeStatus', $this->visibleAsset());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in(Asset::STATUSES)]];
    }
}
