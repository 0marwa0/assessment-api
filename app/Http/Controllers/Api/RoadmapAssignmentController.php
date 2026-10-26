<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoadmapAssignmentRequest;
use App\Http\Resources\RoadmapAssignmentResource;
use App\Models\RoadmapAssignment;
use App\Services\AssignmentTargets;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoadmapAssignmentController extends Controller
{
    private const WITH = ['roadmap.nodes.material', 'roadmap.nodes.simulation', 'roadmap.nodes.assessment'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = RoadmapAssignment::query()
            ->with(self::WITH)
            // Learners only see their own assignments; managers can filter by learner.
            ->when(
                ! $request->user()->isManager(),
                fn ($query) => $query->where('user_id', $request->user()->id),
                fn ($query) => $query->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            )
            ->when($request->filled('roadmap_id'), fn ($query) => $query->where('roadmap_id', $request->integer('roadmap_id')))
            ->latest('assigned_at')
            ->paginate($request->integer('per_page', 50));

        return RoadmapAssignmentResource::collection($assignments);
    }

    /**
     * Assigns to one, several or all brokers (R8), with an optional due date.
     * Brokers who already have this roadmap are skipped.
     */
    public function store(StoreRoadmapAssignmentRequest $request): JsonResponse
    {
        $roadmapId = (int) $request->validated('roadmap_id');
        $userIds = AssignmentTargets::userIds($request);
        $already = RoadmapAssignment::query()
            ->where('roadmap_id', $roadmapId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $created = $userIds->diff($already)->map(fn (int $userId) => RoadmapAssignment::create([
            'user_id' => $userId,
            'roadmap_id' => $roadmapId,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'due_at' => $request->validated('due_at'),
        ])->load(self::WITH))->values();

        // R12: tell each broker (bell + email).
        $created->each(fn (RoadmapAssignment $a) => Notifier::roadmapAssigned($a, $request->user()));

        return response()->json([
            'data' => RoadmapAssignmentResource::collection($created),
            'skipped_user_ids' => $already->values(),
            'message' => AssessmentAssignmentController::summary($created->count(), $already->count()),
        ], $created->isEmpty() ? 200 : 201);
    }
}
