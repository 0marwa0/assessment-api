<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DraftSimulationScorecardRequest;
use App\Models\Simulation;
use App\Services\SimulationScorecard;
use Illuminate\Http\JsonResponse;

class SimulationScorecardDraftController extends Controller
{
    /**
     * R3 "Draft with AI": 3–6 weighted criteria (weights add up to 100).
     * Nothing is saved — the manager edits the draft, then saves.
     */
    public function store(DraftSimulationScorecardRequest $request, SimulationScorecard $scorecard, ?Simulation $simulation = null): JsonResponse
    {
        $stored = $simulation?->only(['title', 'persona', 'scenario_brief', 'objections', 'win_criteria', 'loss_criteria']) ?? [];
        $context = [...$stored, ...array_filter($request->validated(), fn ($v): bool => filled($v))];

        return response()->json(['data' => ['scorecard' => $scorecard->draft($context)]]);
    }
}
