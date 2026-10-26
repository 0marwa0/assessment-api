<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SimulationResource extends JsonResource
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
            'level' => $this->level,
            'time_required' => $this->time_required,
            'status' => $this->status,
            'opening_message' => $this->opening_message,
            'persona' => $this->persona,
            'persona_name' => $this->persona_name,
            'persona_gender' => $this->persona_gender,
            'persona_tone' => $this->persona_tone,
            'scenario_brief' => $this->scenario_brief,
            'objections' => $this->objections ?? [],
            'hidden_motivation' => $this->hidden_motivation,
            'win_criteria' => $this->win_criteria,
            'loss_criteria' => $this->loss_criteria,
            'scorecard' => $this->scorecard,
            // MG9: only present when the index/show loaded the counts (managers).
            'calls_count' => $this->whenCounted('practiceSessions'),
            'roadmaps_count' => $this->whenCounted('roadmaps'),
            'language' => $this->language,
            'voice_engine' => $this->voice_engine ?? 'elevenlabs',
            'content_type' => $this->content_type,
            'project_subtitle' => $this->project_subtitle,
            'launch_tag' => $this->launch_tag,
            'xp' => $this->xp,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
