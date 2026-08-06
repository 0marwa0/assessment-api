<?php

namespace App\Models;

use Database\Factories\SimulationTestMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['simulation_test_session_id', 'role', 'content', 'position'])]
class SimulationTestMessage extends Model
{
    /** @use HasFactory<SimulationTestMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SimulationTestSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SimulationTestSession::class, 'simulation_test_session_id');
    }
}
