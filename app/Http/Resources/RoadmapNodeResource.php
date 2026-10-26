<?php

namespace App\Http\Resources;

use App\Models\Simulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapNodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->content();

        return [
            'id' => $this->id,
            'position' => $this->position,
            'type' => $this->type,
            'material_id' => $this->material_id,
            'simulation_id' => $this->simulation_id,
            'assessment_id' => $this->assessment_id,
            'min_score' => $this->min_score === null ? null : (int) $this->min_score,
            'content' => $content ? ['id' => $content->id, 'title' => $content->title] : null,
            // R11: a step whose simulation is still a draft can't be opened by
            // brokers yet; managers always see it as available.
            'available' => ! ($this->type === 'simulation' && $content instanceof Simulation && ! $content->isPublished() && ! $request->user()?->isManager()),
        ];
    }
}
