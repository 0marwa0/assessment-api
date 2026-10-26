<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentAssignmentRequest;
use App\Http\Resources\AssessmentAssignmentResource;
use App\Models\AssessmentAssignment;
use App\Services\AssignmentTargets;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssessmentAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = AssessmentAssignment::query()
            ->with('assessment')
            // Learners only see their own assignments; managers can filter by learner.
            ->when(
                ! $request->user()->isManager(),
                fn ($query) => $query->where('user_id', $request->user()->id),
                fn ($query) => $query->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            )
            ->when($request->filled('assessment_id'), fn ($query) => $query->where('assessment_id', $request->integer('assessment_id')))
            ->latest('assigned_at')
            ->paginate($request->integer('per_page', 50));

        return AssessmentAssignmentResource::collection($assignments);
    }

    /**
     * Assigns to one, several or all brokers (R8), with an optional due date
     * and retake limit. Brokers who already have this assessment are skipped.
     */
    public function store(StoreAssessmentAssignmentRequest $request): JsonResponse
    {
        $assessmentId = (int) $request->validated('assessment_id');
        $userIds = AssignmentTargets::userIds($request);
        $already = AssessmentAssignment::query()
            ->where('assessment_id', $assessmentId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $created = $userIds->diff($already)->map(fn (int $userId) => AssessmentAssignment::create([
            'user_id' => $userId,
            'assessment_id' => $assessmentId,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'due_at' => $request->validated('due_at'),
            'max_attempts' => $request->validated('max_attempts'),
        ])->load('assessment'))->values();

        // R12: tell each broker (bell + email).
        $created->each(fn (AssessmentAssignment $a) => Notifier::assessmentAssigned($a, $request->user()));

        return response()->json([
            'data' => AssessmentAssignmentResource::collection($created),
            'skipped_user_ids' => $already->values(),
            'message' => self::summary($created->count(), $already->count()),
        ], $created->isEmpty() ? 200 : 201);
    }

    public static function summary(int $created, int $skipped): string
    {
        $text = $created === 1 ? 'Assigned to 1 broker.' : "Assigned to {$created} brokers.";
        if ($skipped > 0) {
            $text .= $skipped === 1 ? ' 1 already had it.' : " {$skipped} already had it.";
        }

        return $text;
    }
}
