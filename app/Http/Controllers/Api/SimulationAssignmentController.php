<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSimulationAssignmentRequest;
use App\Http\Resources\SimulationAssignmentResource;
use App\Models\SimulationAssignment;
use App\Services\AssignmentTargets;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Direct simulation (practice call) assignments, mirroring assessments. */
class SimulationAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = SimulationAssignment::query()
            ->with('simulation')
            // Learners only see their own assignments; managers can filter by learner.
            ->when(
                ! $request->user()->isManager(),
                fn ($query) => $query->where('user_id', $request->user()->id),
                fn ($query) => $query->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            )
            ->when($request->filled('simulation_id'), fn ($query) => $query->where('simulation_id', $request->integer('simulation_id')))
            ->latest('assigned_at')
            ->paginate($request->integer('per_page', 50));

        return SimulationAssignmentResource::collection($assignments);
    }

    /** Assigns to one, several or all brokers; brokers who already have it are skipped. */
    public function store(StoreSimulationAssignmentRequest $request): JsonResponse
    {
        $simulationId = (int) $request->validated('simulation_id');
        $userIds = AssignmentTargets::userIds($request);
        $already = SimulationAssignment::query()
            ->where('simulation_id', $simulationId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $created = $userIds->diff($already)->map(fn (int $userId) => SimulationAssignment::create([
            'user_id' => $userId,
            'simulation_id' => $simulationId,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'due_at' => $request->validated('due_at'),
            'min_score' => $request->validated('min_score'),
        ])->load('simulation'))->values();

        $created->each(fn (SimulationAssignment $a) => Notifier::simulationAssigned($a, $request->user()));

        return response()->json([
            'data' => SimulationAssignmentResource::collection($created),
            'skipped_user_ids' => $already->values(),
            'message' => AssessmentAssignmentController::summary($created->count(), $already->count()),
        ], $created->isEmpty() ? 200 : 201);
    }
}
