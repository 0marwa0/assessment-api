<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['simulation_id', 'created_by', 'token', 'candidate_email', 'candidate_name', 'expires_at'])]
class SimulationInvite extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PracticeSession, $this>
     */
    public function practiceSessions(): HasMany
    {
        return $this->hasMany(PracticeSession::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** R11: links are single-use — saving the guest's call uses it up. */
    public function isUsed(): bool
    {
        return $this->completed_at !== null;
    }

    /** R11: what the manager's invite list shows. */
    public function status(): string
    {
        return match (true) {
            $this->isUsed() => 'used',
            $this->isExpired() => 'expired',
            default => 'unused',
        };
    }

    public function matchesEmail(string $email): bool
    {
        return strtolower(trim($email)) === strtolower(trim($this->candidate_email));
    }
}
