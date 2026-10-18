<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Http\Resources\DashboardResource;
use App\Models\Ticket;
use LogicException;

class DashboardController extends Controller
{
    public function index(DashboardRequest $request): DashboardResource
    {
        $visibleTickets = Ticket::query()->visibleTo($request->user());
        $aggregateQuery = (clone $visibleTickets)->selectRaw('COUNT(*) AS total');

        foreach (Ticket::STATUSES as $status) {
            $aggregateQuery->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS count_'.$status, [$status]);
        }

        $counts = $aggregateQuery->first();
        $stats = [];

        foreach (Ticket::STATUSES as $status) {
            $stats[$status] = (int) $counts->getAttribute('count_'.$status);
        }

        $total = (int) $counts->getAttribute('total');

        if ($total !== array_sum($stats)) {
            throw new LogicException('Les tickets visibles contiennent un statut non conforme.');
        }

        $recentTickets = (clone $visibleTickets)
            ->with(['category:id,name', 'technician:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'reference', 'title', 'status', 'priority', 'category_id', 'technician_id', 'created_at']);

        return new DashboardResource([
            'stats' => ['total' => $total, ...$stats],
            'recent_tickets' => $recentTickets,
        ]);
    }
}
