<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapAssignmentResource extends JsonResource
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
            'user_id' => $this->user_id,
            'roadmap_id' => $this->roadmap_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'roadmap' => new RoadmapResource($this->whenLoaded('roadmap')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
