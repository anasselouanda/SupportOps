<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
            'occurred_at' => $this->occurred_at?->toISOString(),
            'metadata' => $this->metadata ?? [],
        ];
    }
}
