<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestPracticeSessionRequest;
use App\Http\Requests\StoreSimulationInviteRequest;
use App\Http\Requests\VerifySimulationInviteRequest;
use App\Http\Resources\PracticeSessionResource;
use App\Http\Resources\SimulationInviteResource;
use App\Http\Resources\SimulationResource;
use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Models\SimulationInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class SimulationInviteController extends Controller
{
    public function index(Simulation $simulation): AnonymousResourceCollection
    {
        $invites = $simulation->simulationInvites()
            ->latest()
            ->get();

        return SimulationInviteResource::collection($invites);
    }

    public function store(StoreSimulationInviteRequest $request, Simulation $simulation): SimulationInviteResource
    {
        $invite = SimulationInvite::create([
            ...$request->validated(),
            'simulation_id' => $simulation->id,
            'created_by' => $request->user()->id,
            'token' => Str::random(40),
        ]);

        return new SimulationInviteResource($invite->load('simulation'));
    }

    /**
     * Public teaser for the guest landing page — deliberately withholds the
     * candidate_email; that's only checked server-side via unlock().
     */
    public function show(string $token): JsonResponse
    {
        $invite = SimulationInvite::query()->where('token', $token)->firstOrFail();

        if ($invite->isUsed()) {
            return $this->usedResponse();
        }

        return response()->json([
            'data' => [
                'simulation_title' => $invite->simulation->title,
                // R11: the guest landing card (G-Invite) shows who they'll talk to.
                'persona_name' => $invite->simulation->persona_name,
                'level' => $invite->simulation->level,
                'language' => $invite->simulation->language,
                'time_required' => $invite->simulation->time_required,
                'candidate_name' => $invite->candidate_name,
                'expired' => $invite->isExpired(),
                'completed' => $invite->completed_at !== null,
            ],
        ]);
    }

    /**
     * The actual access-control step: the email the guest types must match
     * the one the manager invited. Only on match do we hand back the full
     * simulation payload the call widget needs.
     */
    public function unlock(VerifySimulationInviteRequest $request, string $token): SimulationResource|JsonResponse
    {
        $invite = SimulationInvite::query()->where('token', $token)->firstOrFail();

        if ($invite->isUsed()) {
            return $this->usedResponse();
        }
        abort_if($invite->isExpired(), 410, 'This invite link has expired.');
        abort_unless($invite->matchesEmail($request->string('email')->toString()), 403, 'That email doesn\'t match this invite.');

        // R11: draft simulations are hidden from brokers, but the invited
        // guest still gets them — this is how managers test a draft.
        return new SimulationResource($invite->simulation);
    }

    /**
     * Records the guest's completed call. Re-verifies the email server-side
     * rather than trusting that the frontend already unlocked the page, so
     * the gate can't be skipped by calling the API directly.
     */
    public function completeSession(StoreGuestPracticeSessionRequest $request, string $token): PracticeSessionResource|JsonResponse
    {
        $invite = SimulationInvite::query()->where('token', $token)->firstOrFail();

        if ($invite->isUsed()) {
            return $this->usedResponse();
        }
        abort_if($invite->isExpired(), 410, 'This invite link has expired.');
        abort_unless($invite->matchesEmail($request->string('email')->toString()), 403, 'That email doesn\'t match this invite.');

        // R11: single-use. Claim the invite atomically so two tabs racing to
        // save can't both record a session on the same link.
        $claimed = SimulationInvite::query()
            ->whereKey($invite->id)
            ->whereNull('completed_at')
            ->update(['completed_at' => now()]);
        if ($claimed === 0) {
            return $this->usedResponse();
        }

        $simulation = $invite->simulation;

        $session = PracticeSession::create([
            'simulation_invite_id' => $invite->id,
            'guest_email' => $request->string('email')->toString(),
            'simulation_id' => $simulation->id,
            'simulation_title' => $simulation->title,
            'simulation_level' => $simulation->level,
            'started_at' => $request->validated('started_at'),
            'ended_at' => $request->validated('ended_at'),
            'duration_seconds' => $request->validated('duration_seconds'),
            'transcript' => $request->validated('transcript'),
        ]);

        return (new PracticeSessionResource($session))
            ->additional(['message' => 'Practice session created successfully.']);
    }

    /** R11: the guest page keys its "already used" screen off `reason`. */
    private function usedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This invite link has already been used.',
            'reason' => 'used',
        ], 410);
    }
}
