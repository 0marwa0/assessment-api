<?php

namespace App\Models;

use Database\Factories\SimulationAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'simulation_id', 'assigned_by', 'assigned_at', 'due_at', 'min_score'])]
class SimulationAssignment extends Model
{
    /** @use HasFactory<SimulationAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'simulation_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'min_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @var Collection<int, PracticeSession>|null */
    private ?Collection $learnerSessionsCache = null;

    /**
     * Finished calls (with a transcript) this learner made on this
     * simulation since it was assigned, newest first.
     *
     * @return Collection<int, PracticeSession>
     */
    public function learnerSessions(): Collection
    {
        return $this->learnerSessionsCache ??= PracticeSession::query()
            ->where('user_id', $this->user_id)
            ->where('simulation_id', $this->simulation_id)
            ->where('created_at', '>=', $this->assigned_at)
            ->latest()
            ->latest('id')
            ->get()
            ->filter(fn (PracticeSession $session) => filled($session->transcript))
            ->values();
    }

    /** Done: a finished call since assigning that meets the target score (if any). */
    public function isDone(): bool
    {
        return $this->learnerSessions()->contains(fn (PracticeSession $session) => $this->min_score === null
            || ($session->score !== null && $session->score >= $this->min_score));
    }
}
