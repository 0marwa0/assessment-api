<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SimulationInviteResource extends JsonResource
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
            'token' => $this->token,
            'simulation_id' => $this->simulation_id,
            'simulation_title' => $this->whenLoaded('simulation', fn () => $this->simulation->title),
            'candidate_email' => $this->candidate_email,
            'candidate_name' => $this->candidate_name,
            'expires_at' => $this->expires_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'status' => $this->status(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
