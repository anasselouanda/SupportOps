<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\ListTicketActivityRequest;
use App\Http\Resources\TicketEventResource;
use App\Models\Ticket;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketEventController extends Controller
{
    public function index(ListTicketActivityRequest $request, string $ticket): AnonymousResourceCollection
    {
        $pagination = $request->validated();
        $visibleTicket = Ticket::query()->visibleTo($request->user())->findOrFail($ticket);

        return TicketEventResource::collection($visibleTicket->events()->with('actor:id,name')
            ->orderBy('occurred_at')->orderBy('id')
            ->paginate((int) ($pagination['per_page'] ?? 15))->withQueryString());
    }
}
