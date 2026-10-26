<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRoadmapNodeProgressRequest;
use App\Http\Resources\RoadmapNodeProgressResource;
use App\Models\RoadmapNode;
use App\Models\RoadmapNodeProgress;
use App\Services\RoadmapProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoadmapNodeProgressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $progress = RoadmapNodeProgress::query()
            // Learners only see their own progress; managers can look at anyone's.
            ->when(
                ! $request->user()->isManager(),
                fn ($query) => $query->where('user_id', $request->user()->id),
                fn ($query) => $query->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            )
            ->when(
                $request->filled('roadmap_id'),
                fn ($query) => $query->whereHas(
                    'roadmapNode',
                    fn ($query) => $query->where('roadmap_id', $request->integer('roadmap_id'))
                )
            )
            ->latest('updated_at')
            ->paginate($request->integer('per_page', 15));

        return RoadmapNodeProgressResource::collection($progress);
    }

    public function update(UpdateRoadmapNodeProgressRequest $request, RoadmapNode $roadmapNode, RoadmapProgress $roadmapProgress): RoadmapNodeProgressResource
    {
        // Simulation and assessment steps complete themselves (a saved call, a
        // passed assessment), so a learner can't tick them off by hand (R8).
        abort_if(
            $roadmapNode->type !== 'material' && ! $request->user()->isManager(),
            422,
            'This step completes automatically when you finish the call or pass the assessment.'
        );

        $progress = $roadmapProgress->setStatus($request->user(), $roadmapNode, $request->validated('status'));

        return (new RoadmapNodeProgressResource($progress))
            ->additional(['message' => 'Progress updated successfully.']);
    }
}
