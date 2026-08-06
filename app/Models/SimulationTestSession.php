<?php

namespace App\Models;

use Database\Factories\SimulationTestSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['simulation_id', 'status', 'started_at', 'completed_at'])]
class SimulationTestSession extends Model
{
    /** @use HasFactory<SimulationTestSessionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Simulation, $this>
     */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class);
    }

    /**
     * @return HasMany<SimulationTestMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SimulationTestMessage::class)->orderBy('position');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
