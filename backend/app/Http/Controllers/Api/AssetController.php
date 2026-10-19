<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\ChangeAssetStatusRequest;
use App\Http\Requests\Assets\ListAssetAssignmentsRequest;
use App\Http\Requests\Assets\ListAssetsRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\User;
use App\Services\AssetInventoryService;
use App\Services\AssetWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(ListAssetsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Asset::query()->visibleTo($request->user())->with($this->relations($request->user()));

        if (isset($filters['search'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';

            $query->where(function (Builder $matches) use ($search): void {
                $matches->whereRaw("inventory_code LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("type LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("brand LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("model LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("serial_number LIKE ? ESCAPE '!'", [$search]);
            });
        }

        foreach (['type', 'status'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        return AssetResource::collection($query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    public function store(StoreAssetRequest $request, AssetInventoryService $service): JsonResponse
    {
        $asset = $service->create($request->user(), $request->validated());

        return $this->resource($asset, $request->user())->response()->setStatusCode(201);
    }

    public function show(Request $request, string $asset): AssetResource
    {
        Gate::authorize('viewAny', Asset::class);
        $visibleAsset = Asset::query()->visibleTo($request->user())
            ->with($this->relations($request->user()))->findOrFail($asset);
        Gate::authorize('view', $visibleAsset);

        return new AssetResource($visibleAsset);
    }

    public function update(UpdateAssetRequest $request, string $asset, AssetInventoryService $service): AssetResource
    {
        return $this->resource($service->update($request->user(), $asset, $request->validated()), $request->user());
    }

    public function assignment(AssignAssetRequest $request, string $asset, AssetWorkflowService $service): AssetResource
    {
        $userId = $request->validated('user_id');

        return $this->resource($service->assign($request->user(), $asset, $userId === null ? null : (int) $userId), $request->user());
    }

    public function status(ChangeAssetStatusRequest $request, string $asset, AssetWorkflowService $service): AssetResource
    {
        return $this->resource($service->changeStatus($request->user(), $asset, $request->validated('status')), $request->user());
    }

    public function assignments(ListAssetAssignmentsRequest $request, string $asset): AnonymousResourceCollection
    {
        $visibleAsset = Asset::query()->visibleTo($request->user())->findOrFail($asset);
        Gate::authorize('viewHistory', $visibleAsset);

        return AssetAssignmentResource::collection($visibleAsset->assignments()->with(['user:id,name', 'assignedBy:id,name'])
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate((int) $request->validated('per_page', 15))->withQueryString());
    }

    private function resource(Asset $asset, User $user): AssetResource
    {
        return new AssetResource($asset->load($this->relations($user)));
    }

    /** @return array<int, string> */
    private function relations(User $user): array
    {
        return $user->role === User::ROLE_TECHNICIAN ? [] : ['currentAssignment.user:id,name'];
    }
}
