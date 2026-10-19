<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = [
            'id' => $this->id,
            'inventory_code' => $this->inventory_code,
            'type' => $this->type,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'status' => $this->status,
        ];

        if ($request->user()?->role === User::ROLE_TECHNICIAN) {
            return $summary;
        }

        $assignment = $this->relationLoaded('currentAssignment') ? $this->currentAssignment : null;

        return [
            ...$summary,
            'purchase_date' => $this->purchase_date?->format('Y-m-d'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'current_assignment' => $assignment === null ? null : [
                'id' => $assignment->id,
                'user' => ['id' => $assignment->user->id, 'name' => $assignment->user->name],
                'started_at' => $assignment->started_at?->toISOString(),
            ],
        ];
    }
}
