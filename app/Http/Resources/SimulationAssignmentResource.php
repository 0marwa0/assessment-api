<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SimulationAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sessions = $this->resource->learnerSessions();
        $last = $sessions->first();
        $simulation = $this->whenLoaded('simulation');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'simulation_id' => $this->simulation_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'min_score' => $this->min_score,
            // Status for Home, from this learner's calls since assigning.
            'calls_count' => $sessions->count(),
            'done' => $this->resource->isDone(),
            'best_score' => $sessions->max('score'),
            'last_session' => $last ? [
                'id' => $last->id,
                'score' => $last->score,
                'created_at' => $last->created_at?->toISOString(),
            ] : null,
            'simulation' => $this->when($this->relationLoaded('simulation') && $simulation !== null, fn () => [
                'id' => $simulation->id,
                'title' => $simulation->title,
                'description' => $simulation->description,
                'level' => $simulation->level,
                'time_required' => $simulation->time_required,
                'persona_name' => $simulation->persona_name,
                'status' => $simulation->status,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
