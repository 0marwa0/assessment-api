<?php

namespace App\Models;

use Database\Factories\SimulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'description', 'level', 'time_required', 'status', 'opening_message', 'persona', 'persona_name',
    'persona_gender', 'persona_tone', 'scenario_brief', 'language', 'content_type', 'project_subtitle', 'launch_tag', 'xp',
    'objections', 'hidden_motivation', 'win_criteria', 'loss_criteria', 'voice_engine', 'scorecard',
])]
class Simulation extends Model
{
    /** @use HasFactory<SimulationFactory> */
    use HasFactory;

    /** R11: only published simulations are visible to brokers. */
    public const STATUS_PUBLISHED = 'published';

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'objections' => 'array',
            'scorecard' => 'array',
        ];
    }

    /**
     * @return HasMany<SimulationTestSession, $this>
     */
    public function testSessions(): HasMany
    {
        return $this->hasMany(SimulationTestSession::class);
    }

    /**
     * @return HasMany<SimulationInvite, $this>
     */
    public function simulationInvites(): HasMany
    {
        return $this->hasMany(SimulationInvite::class);
    }

    /**
     * MG9: real broker calls made on this simulation.
     *
     * @return HasMany<PracticeSession, $this>
     */
    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class);
    }

    /**
     * MG9: roadmap steps that use this simulation (they block deletion).
     *
     * @return HasMany<RoadmapNode, $this>
     */
    public function roadmapNodes(): HasMany
    {
        return $this->hasMany(RoadmapNode::class);
    }
}
