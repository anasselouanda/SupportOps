<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssetSelectionRequest;
use App\Http\Requests\Assets\ListAssetRecipientsRequest;
use App\Http\Resources\AssetRecipientResource;
use App\Http\Resources\AssetSummaryResource;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetSelectionController extends Controller
{
    public function recipients(ListAssetRecipientsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $users = User::query()->where('is_active', true);

        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $users->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return AssetRecipientResource::collection($users->orderBy('name')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    public function ticketAssets(AssetSelectionRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $assets = Asset::query();

        if (! $request->user()->isActiveAdministrator()) {
            $assets->whereIn('status', ['assigned', 'maintenance'])
                ->whereHas('currentAssignment', fn (Builder $query) => $query->where('user_id', $request->user()->getKey()));
        }

        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $assets->where(function (Builder $query) use ($pattern): void {
                foreach (['inventory_code', 'brand', 'model', 'serial_number'] as $index => $field) {
                    $query->{$index === 0 ? 'whereRaw' : 'orWhereRaw'}($field." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        foreach (['type', 'status'] as $filter) {
            if (isset($filters[$filter])) {
                $assets->where($filter, $filters[$filter]);
            }
        }

        return AssetSummaryResource::collection($assets->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', $filters['direction'] ?? 'desc')->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    private function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
