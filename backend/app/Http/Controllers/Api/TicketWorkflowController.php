<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\ChangeTicketStatusRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketWorkflowService;

class TicketWorkflowController extends Controller
{
    public function update(UpdateTicketRequest $request, string $ticket, TicketWorkflowService $service): TicketResource
    {
        return $this->resource($service->update($request->user(), $ticket, $request->validated()));
    }

    public function assign(AssignTicketRequest $request, string $ticket, TicketWorkflowService $service): TicketResource
    {
        $technicianId = $request->validated('technician_id');

        return $this->resource($service->assign($request->user(), $ticket, $technicianId === null ? null : (int) $technicianId));
    }

    public function status(ChangeTicketStatusRequest $request, string $ticket, TicketWorkflowService $service): TicketResource
    {
        return $this->resource($service->changeStatus($request->user(), $ticket, $request->validated('status'), $request->validated('resolution_note')));
    }

    private function resource(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load(['category:id,name', 'requester:id,name', 'technician:id,name']));
    }
}
