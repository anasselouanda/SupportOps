<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'requester' => ['id' => $this->requester->id, 'name' => $this->requester->name],
            'technician' => $this->technician === null ? null : ['id' => $this->technician->id, 'name' => $this->technician->name],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
        ];
    }
}
