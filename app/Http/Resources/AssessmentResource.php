<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
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
            'type' => $this->type,
            'status' => $this->status,
            'scoring_rules' => $this->scoring_rules,
            'usage_analytics' => $this->usage_analytics,
            // Learners only get the blocks that are switched on (Q2).
            'blocks' => BlockResource::collection($this->whenLoaded(
                'blocks',
                fn () => $request->user()?->isManager()
                    ? $this->blocks
                    : $this->blocks->filter(fn ($block) => $block->enabled !== false)->values()
            )),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
