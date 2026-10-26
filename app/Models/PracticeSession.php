<?php

namespace App\Models;

use Database\Factories\PracticeSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'simulation_invite_id', 'guest_email', 'simulation_id', 'simulation_title', 'simulation_level', 'started_at', 'ended_at', 'duration_seconds', 'transcript', 'score', 'feedback'])]
class PracticeSession extends Model
{
    /** @use HasFactory<PracticeSessionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'simulation_id' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'transcript' => 'array',
            'score' => 'integer',
            'ai_report' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Simulation, $this>
     */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SimulationInvite, $this>
     */
    public function simulationInvite(): BelongsTo
    {
        return $this->belongsTo(SimulationInvite::class);
    }

    /**
     * Manager review (MG2 Review Queue); none until a manager reviews the call.
     *
     * @return HasOne<PracticeSessionReview, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(PracticeSessionReview::class);
    }

    /**
     * @return HasMany<PracticeSessionComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PracticeSessionComment::class)->orderBy('turn_index')->orderBy('id');
    }

    /**
     * @return HasMany<PracticeSessionRereview, $this>
     */
    public function rereviews(): HasMany
    {
        return $this->hasMany(PracticeSessionRereview::class)->latest('id');
    }
}
