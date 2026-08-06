<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SimulationTestSessionResource;
use App\Models\Simulation;
use App\Models\SimulationTestSession;
use Illuminate\Support\Facades\DB;

class SimulationTestSessionController extends Controller
{
    public function store(Simulation $simulation): SimulationTestSessionResource
    {
        $session = DB::transaction(function () use ($simulation): SimulationTestSession {
            $session = $simulation->testSessions()->create([
                'status' => 'active',
                'started_at' => now(),
            ]);

            $session->messages()->create([
                'role' => 'simulation',
                'content' => $simulation->opening_message,
                'position' => 0,
            ]);

            return $session;
        });

        return (new SimulationTestSessionResource($session->load('messages')))
            ->additional(['message' => 'Simulation test session created successfully.']);
    }
}
