<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssessmentAssignment;
use App\Models\Nudge;
use App\Models\RoadmapAssignment;
use App\Models\SimulationAssignment;
use App\Models\User;
use App\Services\AssignmentItems;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// R12: manager "Nudge" from the Coaching Hub — one nudge per broker per item per day.
class NudgeController extends Controller
{
    public const DEFAULT_MESSAGE = 'Friendly reminder — can you finish this today?';

    /** Manager: recent nudges (last 30 days), newest first, for "Nudged today" / "Last nudged". */
    public function index(Request $request): JsonResponse
    {
        $nudges = Nudge::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest()
            ->latest('id')
            ->limit(500)
            ->get();

        return response()->json(['data' => $nudges->map(fn (Nudge $n): array => self::toArray($n))->values()]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', Rule::in(['roadmap', 'assessment', 'simulation'])],
            'item_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:500'],
            'via_app' => ['nullable', 'boolean'],
            'via_email' => ['nullable', 'boolean'],
        ]);

        $viaApp = $validated['via_app'] ?? true;
        $viaEmail = $validated['via_email'] ?? true;
        abort_unless($viaApp || $viaEmail, 422, 'Pick at least one way to send the nudge.');

        $assignment = match ($validated['item_type']) {
            'roadmap' => RoadmapAssignment::query()->with('roadmap')->where('user_id', $user->id)->where('roadmap_id', $validated['item_id'])->first(),
            'simulation' => SimulationAssignment::query()->with('simulation')->where('user_id', $user->id)->where('simulation_id', $validated['item_id'])->first(),
            default => AssessmentAssignment::query()->with('assessment')->where('user_id', $user->id)->where('assessment_id', $validated['item_id'])->first(),
        };
        abort_if($assignment === null, 422, "{$user->name} doesn't have this assigned.");

        $last = Nudge::query()
            ->where('user_id', $user->id)
            ->where('item_type', $validated['item_type'])
            ->where('item_id', $validated['item_id'])
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
        abort_if($last, 429, "You already nudged {$user->name} about this today. One nudge per item per day.");

        $message = trim((string) ($validated['message'] ?? '')) ?: self::DEFAULT_MESSAGE;

        $nudge = Nudge::create([
            'manager_id' => $request->user()->id,
            'user_id' => $user->id,
            'item_type' => $validated['item_type'],
            'item_id' => $validated['item_id'],
            'message' => $message,
            'via_app' => $viaApp,
            'via_email' => $viaEmail,
        ]);

        Notifier::nudge(
            $user,
            $request->user(),
            AssignmentItems::describe($assignment),
            $message,
            AssignmentItems::statusPhrase($assignment),
            $viaApp,
            $viaEmail,
        );

        return response()->json([
            'data' => self::toArray($nudge),
            'message' => "Nudge sent to {$user->name}.",
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Nudge $n): array
    {
        return [
            'id' => $n->id,
            'user_id' => $n->user_id,
            'item_type' => $n->item_type,
            'item_id' => $n->item_id,
            'message' => $n->message,
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
