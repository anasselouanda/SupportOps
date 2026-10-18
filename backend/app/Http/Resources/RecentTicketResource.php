<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecentTicketResource extends JsonResource
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
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => ['id' => $this->category->id, 'name' => $this->category->name],
            'technician' => $this->technician === null ? null : ['id' => $this->technician->id, 'name' => $this->technician->name],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
