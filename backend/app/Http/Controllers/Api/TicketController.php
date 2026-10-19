<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\ListTicketsRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    public function index(ListTicketsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = Ticket::query()->visibleTo($request->user())->with($this->relations())->withAssetFor($request->user());

        if (isset($filters['search'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';

            $query->where(function (Builder $matches) use ($search): void {
                $matches->whereRaw("title LIKE ? ESCAPE '!'", [$search])
                    ->orWhereRaw("reference LIKE ? ESCAPE '!'", [$search]);
            });
        }

        foreach (['status', 'priority'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return TicketResource::collection($query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))->withQueryString());
    }

    public function store(StoreTicketRequest $request, TicketService $service): JsonResponse
    {
        $ticket = $service->create($request->user(), $request->validated());
        $ticket = Ticket::query()->with($this->relations())->withAssetFor($request->user())->findOrFail($ticket->getKey());

        return (new TicketResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $ticket): TicketResource
    {
        $visibleTicket = Ticket::query()->visibleTo($request->user())
            ->with($this->relations())->withAssetFor($request->user())->findOrFail($ticket);
        Gate::authorize('view', $visibleTicket);

        return new TicketResource($visibleTicket);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['category:id,name,is_active', 'requester:id,name', 'technician:id,name', 'asset'];
    }
}
