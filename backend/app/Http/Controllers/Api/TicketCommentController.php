<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\ListTicketActivityRequest;
use App\Http\Requests\Tickets\StoreTicketCommentRequest;
use App\Http\Resources\TicketCommentResource;
use App\Models\Ticket;
use App\Services\TicketCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketCommentController extends Controller
{
    public function index(ListTicketActivityRequest $request, string $ticket): AnonymousResourceCollection
    {
        $pagination = $request->validated();
        $visibleTicket = Ticket::query()->visibleTo($request->user())->findOrFail($ticket);

        return TicketCommentResource::collection($visibleTicket->comments()->with('author:id,name')
            ->orderBy('created_at')->orderBy('id')
            ->paginate((int) ($pagination['per_page'] ?? 15))->withQueryString());
    }

    public function store(StoreTicketCommentRequest $request, string $ticket, TicketCommentService $service): JsonResponse
    {
        $comment = $service->create($request->user(), $ticket, $request->validated('content'));
        $comment->load('author:id,name');

        return (new TicketCommentResource($comment))->response()->setStatusCode(201);
    }
}
