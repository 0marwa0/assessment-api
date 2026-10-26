<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PracticeSessionResource extends JsonResource
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
            // Only present when the controller eager-loads the user (index), so
            // the manager's Review Queue can show who made each call.
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'simulation_invite_id' => $this->simulation_invite_id,
            'guest_email' => $this->guest_email,
            'simulation_id' => $this->simulation_id,
            'simulation_title' => $this->simulation_title,
            'simulation_level' => $this->simulation_level,
            'started_at' => $this->started_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'duration_seconds' => $this->duration_seconds,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'ai_report' => $this->ai_report,
            'transcript' => $this->transcript,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
