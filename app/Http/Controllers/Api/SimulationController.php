<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSimulationRequest;
use App\Http\Requests\UpdateSimulationRequest;
use App\Http\Resources\SimulationResource;
use App\Models\RoadmapNode;
use App\Models\Simulation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SimulationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $simulations = Simulation::query()
            ->when($request->filled('content_type'), fn ($query) => $query->where('content_type', $request->string('content_type')))
            // R11: brokers only ever see published simulations; drafts stay
            // with managers (who test them via a guest invite link).
            ->when(! $request->user()?->isManager(), fn ($query) => $query->where('status', Simulation::STATUS_PUBLISHED))
            // MG9: managers see how many calls were made and which roadmaps use it.
            ->when($request->user()?->isManager(), fn ($query) => $query->withCount($this->usageCounts()))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return SimulationResource::collection($simulations);
    }

    public function store(StoreSimulationRequest $request): SimulationResource
    {
        $simulation = Simulation::create([
            ...$request->validated(),
            // opening_message is optional now but the column is NOT NULL.
            'opening_message' => $request->validated('opening_message') ?? '',
            'language' => $request->validated('language', 'en'),
            'content_type' => $request->validated('content_type', 'scenario'),
            'voice_engine' => $request->validated('voice_engine', 'elevenlabs'),
        ]);

        return (new SimulationResource($simulation))
            ->additional(['message' => 'Simulation created successfully.']);
    }

    public function show(Request $request, Simulation $simulation): SimulationResource
    {
        // R11: a draft looks like it doesn't exist to brokers.
        abort_unless($request->user()?->isManager() || $simulation->isPublished(), 404);

        if ($request->user()?->isManager()) {
            $simulation->loadCount($this->usageCounts());
        }

        return new SimulationResource($simulation);
    }

    public function update(UpdateSimulationRequest $request, Simulation $simulation): SimulationResource
    {
        $validated = $request->validated();
        if (array_key_exists('opening_message', $validated) && $validated['opening_message'] === null) {
            $validated['opening_message'] = '';
        }

        $simulation->fill($validated);
        $simulation->save();

        return (new SimulationResource($simulation))
            ->additional(['message' => 'Simulation updated successfully.']);
    }

    public function destroy(Simulation $simulation): JsonResponse
    {
        // Roadmap steps reference simulations with restrictOnDelete; say so
        // plainly instead of failing on the foreign key (MG9 delete pop-up).
        $roadmaps = RoadmapNode::query()->where('simulation_id', $simulation->id)->distinct()->count('roadmap_id');
        abort_if($roadmaps > 0, 422, "It's used in {$roadmaps} roadmap".($roadmaps === 1 ? '' : 's').'. Remove it from there first.');

        $simulation->delete();

        return response()->json(status: 204);
    }

    /**
     * MG9: calls made on a simulation and the distinct roadmaps that use it.
     *
     * @return array<int|string, mixed>
     */
    private function usageCounts(): array
    {
        return [
            'practiceSessions',
            'roadmapNodes as roadmaps_count' => fn (Builder $query) => $query->select(DB::raw('count(distinct roadmap_id)')),
        ];
    }
}
