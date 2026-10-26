<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'source' => $this->source,
            'nodes' => RoadmapNodeResource::collection($this->whenLoaded('nodes')),
            // Only on the manager's library list (RoadmapController@index).
            'assigned_count' => $this->when($this->resource->getAttribute('assigned_count') !== null, fn () => $this->resource->getAttribute('assigned_count')),
            'completed_count' => $this->when($this->resource->getAttribute('completed_count') !== null, fn () => $this->resource->getAttribute('completed_count')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
