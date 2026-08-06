<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSimulationTestMessageRequest;
use App\Http\Resources\SimulationTestMessageResource;
use App\Models\SimulationTestSession;

class SimulationTestSessionMessageController extends Controller
{
    public function store(StoreSimulationTestMessageRequest $request, SimulationTestSession $session): SimulationTestMessageResource
    {
        $message = $session->messages()->create([
            'role' => $request->validated('role'),
            'content' => $request->validated('content'),
            'position' => $session->messages()->count(),
        ]);

        return (new SimulationTestMessageResource($message))
            ->additional(['message' => 'Simulation test message created successfully.']);
    }
}
