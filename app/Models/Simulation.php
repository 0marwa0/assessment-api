<?php

namespace App\Models;

use Database\Factories\SimulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'level', 'time_required', 'status', 'opening_message'])]
class Simulation extends Model
{
    /** @use HasFactory<SimulationFactory> */
    use HasFactory;

    /**
     * @return HasMany<SimulationTestSession, $this>
     */
    public function testSessions(): HasMany
    {
        return $this->hasMany(SimulationTestSession::class);
    }
}
