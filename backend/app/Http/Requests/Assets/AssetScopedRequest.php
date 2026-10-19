<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use Illuminate\Support\Facades\Gate;

class AssetScopedRequest extends AssetRequest
{
    private ?Asset $resolvedAsset = null;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        Gate::authorize('viewAny', Asset::class);
        Gate::authorize('view', $this->visibleAsset());

        return true;
    }

    protected function visibleAsset(): Asset
    {
        return $this->resolvedAsset ??= Asset::query()->visibleTo($this->user())
            ->findOrFail($this->route('asset'));
    }
}
