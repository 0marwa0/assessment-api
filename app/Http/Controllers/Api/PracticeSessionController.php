<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePracticeSessionRequest;
use App\Http\Requests\UpdatePracticeSessionRequest;
use App\Http\Resources\PracticeSessionResource;
use App\Models\PracticeSession;
use App\Models\Simulation;
use App\Services\AttemptFinalizer;
use App\Services\RoadmapProgress;
use App\Services\SimulationScorecard;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PracticeSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = PracticeSession::query()
            ->with('user:id,name')
            ->when(! $request->user()->isManager(), fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($request->has('score'), function ($query) use ($request): void {
                if (strtolower($request->string('score')->toString()) === 'null') {
                    $query->whereNull('score');
                } else {
                    $query->where('score', $request->integer('score'));
                }
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return PracticeSessionResource::collection($sessions);
    }

    public function store(StorePracticeSessionRequest $request, RoadmapProgress $roadmapProgress): PracticeSessionResource
    {
        // R11: brokers can't practise (or save calls on) a draft simulation.
        // Guest invite calls are saved by SimulationInviteController instead.
        $simulation = Simulation::query()->find($request->integer('simulation_id'));
        abort_if(
            $simulation?->status === 'draft' && ! $request->user()->isManager(),
            422,
            'This simulation is a draft and can\'t be practised yet.'
        );

        $session = PracticeSession::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        // Finishing a call (with something said) completes the matching roadmap step (R8).
        // MG8: steps with a "pass score to unlock" wait for a score (AI report / manager review).
        $roadmapProgress->completeForSession($session);
        // R4: a test attempt waiting for this call's score can now be finalized.
        app(AttemptFinalizer::class)->finalizeForSession($session);

        return (new PracticeSessionResource($session))
            ->additional(['message' => 'Practice session created successfully.']);
    }

    public function update(UpdatePracticeSessionRequest $request, PracticeSession $practice_session): PracticeSessionResource
    {
        $practice_session->fill($request->validated());
        $practice_session->save();

        // MG8: a graded score can unlock a score-gated roadmap step.
        app(RoadmapProgress::class)->completeForSession($practice_session);
        // R4: a test attempt waiting for this call's score can now be finalized.
        app(AttemptFinalizer::class)->finalizeForSession($practice_session);

        return (new PracticeSessionResource($practice_session))
            ->additional(['message' => 'Practice session graded successfully.']);
    }

    public function generateAiReport(Request $request, PracticeSession $practice_session): PracticeSessionResource
    {
        abort_unless(
            $request->user()->isManager() || $practice_session->user_id === $request->user()->id,
            403,
            'You can only generate reports for your own calls.'
        );
        abort_if(blank($practice_session->transcript), 422, 'This session has no transcript to analyze.');
        // Report guard: a saved report is returned as-is (no second paid AI
        // call, no silent rewrite). Only managers can force a fresh one.
        $force = $request->boolean('force');
        abort_if($force && ! $request->user()->isManager(), 403, 'Only managers can regenerate a report.');

        // One generation at a time per call: a second request (two viewers,
        // two tabs) waits for the first and then gets its saved report.
        return Cache::lock("practice-session-ai-report:{$practice_session->id}", 180)
            ->block(150, fn () => $this->writeAiReport($practice_session->refresh(), $force));
    }

    private function writeAiReport(PracticeSession $practice_session, bool $force): PracticeSessionResource
    {
        if ($practice_session->ai_report !== null && ! $force) {
            return (new PracticeSessionResource($practice_session))
                ->additional(['message' => 'AI report already exists.']);
        }
        abort_if(blank(config('services.bedrock.model_id')), 500, 'BEDROCK_MODEL_ID is not configured on the server.');

        $transcriptText = collect($practice_session->transcript)
            ->map(fn (array $turn): string => sprintf(
                '%s: %s',
                ($turn['role'] ?? 'agent') === 'user' ? 'Broker' : 'Customer',
                $turn['text'] ?? ''
            ))
            ->implode("\n");

        // R3: the simulation's weighted scorecard replaces the fixed rubric.
        $scorecard = app(SimulationScorecard::class);
        $criteria = $scorecard->criteria($practice_session->simulation?->scorecard);
        $rubric = $scorecard->reportPrompt($criteria);

        $client = new BedrockRuntimeClient([
            'region' => config('services.bedrock.region'),
            'version' => 'latest',
        ]);

        try {
            $result = $client->invokeModel([
                'modelId' => config('services.bedrock.model_id'),
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode([
                    'anthropic_version' => 'bedrock-2023-05-31',
                    'max_tokens' => 2000,
                    'messages' => [[
                        'role' => 'user',
                        'content' => <<<PROMPT
                            You are a sales coaching analyst. Analyze this real-estate sales roleplay call and produce a structured coaching report for the broker.

                            Scenario: {$practice_session->simulation_title} ({$practice_session->simulation_level})
                            Duration: {$practice_session->duration_seconds} seconds

                            Transcript:
                            {$transcriptText}

                            {$rubric}
                            PROMPT,
                    ]],
                    'tools' => [$this->aiReportTool($scorecard->reportSchema($criteria))],
                    'tool_choice' => ['type' => 'tool', 'name' => 'submit_sales_report'],
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (AwsException $exception) {
            abort(502, 'AI report generation failed: '.$exception->getAwsErrorMessage());
        }

        $body = json_decode($result['body']->getContents(), true);

        $toolUse = collect($body['content'] ?? [])->firstWhere('type', 'tool_use');

        abort_if(! $toolUse, 502, 'The AI did not return a structured report.');

        $report = $scorecard->applyWeights($toolUse['input'], $criteria);

        $practice_session->ai_report = $report;

        // A manager's reviewed score always wins; otherwise the report's score is the call's score.
        if ($practice_session->score === null || ($force && $practice_session->review()->doesntExist())) {
            $practice_session->score = $report['score'];
        }

        if (blank($practice_session->feedback)) {
            $practice_session->feedback = implode(' ', [
                ...($report['coaching']['went_well'] ?? []),
                ...($report['coaching']['areas_for_improvement'] ?? []),
            ]);
        }

        $practice_session->save();

        // MG8: the AI score can unlock a score-gated roadmap step.
        app(RoadmapProgress::class)->completeForSession($practice_session);
        // R4: a test attempt waiting for this call's score can now be finalized.
        app(AttemptFinalizer::class)->finalizeForSession($practice_session);

        return (new PracticeSessionResource($practice_session))
            ->additional(['message' => 'AI report generated successfully.']);
    }

    /**
     * @param  array<string, mixed>  $scorecardSchema
     * @return array<string, mixed>
     */
    private function aiReportTool(array $scorecardSchema): array
    {
        return [
            'name' => 'submit_sales_report',
            'description' => 'Submit the structured sales conversation coaching report.',
            'input_schema' => [
                'type' => 'object',
                'required' => ['score', 'status_label', 'executive_summary', 'coaching', 'key_moments', 'sentiment_arc', 'indicator_matrix', 'scorecard'],
                'properties' => [
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'status_label' => ['type' => 'string', 'description' => 'e.g. "Likely to Close", "Needs Follow-up", "At Risk"'],
                    'executive_summary' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 4],
                    'coaching' => [
                        'type' => 'object',
                        'required' => ['went_well', 'areas_for_improvement'],
                        'properties' => [
                            'went_well' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
                            'areas_for_improvement' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 3],
                        ],
                    ],
                    'key_moments' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 6,
                        'items' => [
                            'type' => 'object',
                            'required' => ['quote', 'tag', 'tone'],
                            'properties' => [
                                'quote' => ['type' => 'string', 'description' => 'A short, verbatim or near-verbatim excerpt from the transcript'],
                                'tag' => ['type' => 'string', 'enum' => ['Buying Signal', 'Price Objection', 'Objection', 'Other']],
                                'tone' => ['type' => 'string', 'enum' => ['positive', 'negative', 'neutral']],
                            ],
                        ],
                    ],
                    'sentiment_arc' => [
                        'type' => 'object',
                        'required' => ['points', 'label'],
                        'properties' => [
                            'points' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                                'minItems' => 3,
                                'maxItems' => 6,
                                'description' => 'Buyer sentiment over the course of the call, 0 (cold) to 100 (very warm)',
                            ],
                            'label' => ['type' => 'string', 'description' => 'e.g. "Neutral -> Hesitant -> High"'],
                        ],
                    ],
                    'indicator_matrix' => [
                        'type' => 'object',
                        'required' => ['discounting_impulse_instances', 'concession_points', 'tone_confidence_score', 'anchor_effectiveness_score', 'patience_index', 'silence_comfort'],
                        'properties' => [
                            'discounting_impulse_instances' => ['type' => 'integer', 'description' => 'Number of times the broker offered a discount unprompted'],
                            'concession_points' => [
                                'type' => 'array',
                                'maxItems' => 4,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['text', 'sentiment'],
                                    'properties' => [
                                        'text' => ['type' => 'string'],
                                        'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                                    ],
                                ],
                            ],
                            'tone_confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'anchor_effectiveness_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'How effectively the broker anchored value before price'],
                            'patience_index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'description' => 'Higher means the broker showed more patience through objections'],
                            'silence_comfort' => [
                                'type' => 'object',
                                'required' => ['broker', 'customer'],
                                'properties' => [
                                    'broker' => ['type' => 'string', 'description' => 'One short sentence on the broker\'s comfort with pauses'],
                                    'customer' => ['type' => 'string', 'description' => 'One short sentence on the customer\'s comfort with pauses'],
                                ],
                            ],
                        ],
                    ],
                    'scorecard' => $scorecardSchema,
                ],
            ],
        ];
    }
}
