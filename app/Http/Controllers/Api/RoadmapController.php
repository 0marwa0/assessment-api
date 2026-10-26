<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoadmapRequest;
use App\Http\Requests\UpdateRoadmapRequest;
use App\Http\Resources\RoadmapResource;
use App\Models\Roadmap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RoadmapController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $roadmaps = Roadmap::query()
            ->with(['nodes.material', 'nodes.simulation', 'nodes.assessment'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search').'%';

                $query->where(fn ($query) => $query->where('title', 'like', $search)
                    ->orWhere('description', 'like', $search));
            })
            ->latest()
            ->paginate($request->integer('per_page', 15));

        // Managers' Roadmaps library shows "n brokers assigned · n completed" (MG6).
        if ($request->user()?->isManager()) {
            $this->attachAssignmentCounts($roadmaps->getCollection());
        }

        return RoadmapResource::collection($roadmaps)
            ->additional([
                'meta' => [
                    'status_counts' => $this->statusCounts(),
                ],
            ]);
    }

    public function store(StoreRoadmapRequest $request): RoadmapResource
    {
        $roadmap = DB::transaction(function () use ($request): Roadmap {
            $roadmap = Roadmap::create([
                ...$request->safe()->only(['title', 'description']),
                'status' => $request->validated('status', 'draft'),
                'source' => $request->validated('source', 'manual'),
                'created_by' => $request->user()->id,
            ]);

            $this->syncNodes($roadmap, $request->validated('nodes'));

            return $roadmap;
        });

        return (new RoadmapResource($roadmap->load(['nodes.material', 'nodes.simulation', 'nodes.assessment'])))
            ->additional(['message' => 'Roadmap created successfully.']);
    }

    public function show(Roadmap $roadmap): RoadmapResource
    {
        return new RoadmapResource($roadmap->load(['nodes.material', 'nodes.simulation', 'nodes.assessment']));
    }

    public function update(UpdateRoadmapRequest $request, Roadmap $roadmap): RoadmapResource
    {
        $roadmap = DB::transaction(function () use ($request, $roadmap): Roadmap {
            $roadmap->fill($request->safe()->only(['title', 'description', 'status', 'source']));
            $roadmap->save();

            if ($request->safe()->has('nodes')) {
                $roadmap->nodes()->delete();
                $this->syncNodes($roadmap, $request->validated('nodes'));
            }

            return $roadmap;
        });

        return (new RoadmapResource($roadmap->load(['nodes.material', 'nodes.simulation', 'nodes.assessment'])))
            ->additional(['message' => 'Roadmap updated successfully.']);
    }

    public function destroy(Roadmap $roadmap): JsonResponse
    {
        $roadmap->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function syncNodes(Roadmap $roadmap, array $nodes): void
    {
        foreach ($nodes as $index => $node) {
            $type = $node['type'] ?? 'material';

            $roadmap->nodes()->create([
                'type' => $type,
                'material_id' => $type === 'material' ? $node['material_id'] : null,
                'simulation_id' => $type === 'simulation' ? $node['simulation_id'] : null,
                'assessment_id' => $type === 'assessment' ? $node['assessment_id'] : null,
                'min_score' => $type === 'simulation' && isset($node['min_score']) ? (int) $node['min_score'] : null,
                'position' => $node['position'] ?? $index,
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Roadmap::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return array_merge([
            'draft' => 0,
            'published' => 0,
        ], $counts);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Roadmap>  $roadmaps
     */
    private function attachAssignmentCounts(\Illuminate\Support\Collection $roadmaps): void
    {
        $assignments = \App\Models\RoadmapAssignment::query()
            ->whereIn('roadmap_id', $roadmaps->pluck('id'))
            ->get(['user_id', 'roadmap_id'])
            ->groupBy('roadmap_id');

        $doneByUser = \App\Models\RoadmapNodeProgress::query()
            ->where('status', 'done')
            ->whereIn('user_id', $assignments->flatten()->pluck('user_id')->unique())
            ->get(['user_id', 'roadmap_node_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('roadmap_node_id')->flip());

        foreach ($roadmaps as $roadmap) {
            $nodeIds = $roadmap->nodes->pluck('id');
            $forRoadmap = $assignments->get($roadmap->id, collect());
            $completed = $forRoadmap->filter(function ($assignment) use ($nodeIds, $doneByUser): bool {
                $done = $doneByUser->get($assignment->user_id, collect());

                return $nodeIds->isNotEmpty() && $nodeIds->every(fn ($id) => $done->has($id));
            })->count();

            $roadmap->setAttribute('assigned_count', $forRoadmap->count());
            $roadmap->setAttribute('completed_count', $completed);
        }
    }
}
