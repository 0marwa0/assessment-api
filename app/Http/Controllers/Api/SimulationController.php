<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSimulationRequest;
use App\Http\Requests\UpdateSimulationRequest;
use App\Http\Resources\SimulationResource;
use App\Models\Simulation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SimulationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $simulations = Simulation::query()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return SimulationResource::collection($simulations);
    }

    public function store(StoreSimulationRequest $request): SimulationResource
    {
        $simulation = Simulation::create($request->validated());

        return (new SimulationResource($simulation))
            ->additional(['message' => 'Simulation created successfully.']);
    }

    public function show(Simulation $simulation): SimulationResource
    {
        return new SimulationResource($simulation);
    }

    public function update(UpdateSimulationRequest $request, Simulation $simulation): SimulationResource
    {
        $simulation->fill($request->validated());
        $simulation->save();

        return (new SimulationResource($simulation))
            ->additional(['message' => 'Simulation updated successfully.']);
    }

    public function destroy(Simulation $simulation): JsonResponse
    {
        $simulation->delete();

        return response()->json(status: 204);
    }
}
