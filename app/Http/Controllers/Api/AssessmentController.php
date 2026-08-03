<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Requests\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AssessmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $assessments = Assessment::query()
            ->with('questions')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return AssessmentResource::collection($assessments);
    }

    public function store(StoreAssessmentRequest $request): AssessmentResource
    {
        $assessment = DB::transaction(function () use ($request): Assessment {
            $assessment = Assessment::create($request->safe()->only(['type', 'status']));

            $this->syncQuestions($assessment, $request->validated('questions'));

            return $assessment;
        });

        return (new AssessmentResource($assessment->load('questions')))
            ->additional(['message' => 'Assessment created successfully.']);
    }

    public function show(Assessment $assessment): AssessmentResource
    {
        return new AssessmentResource($assessment->load('questions'));
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): AssessmentResource
    {
        $assessment = DB::transaction(function () use ($request, $assessment): Assessment {
            $assessment->fill($request->safe()->only(['type', 'status']));
            $assessment->save();

            if ($request->safe()->has('questions')) {
                $assessment->questions()->delete();
                $this->syncQuestions($assessment, $request->validated('questions'));
            }

            return $assessment;
        });

        return (new AssessmentResource($assessment->load('questions')))
            ->additional(['message' => 'Assessment updated successfully.']);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $assessment->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function syncQuestions(Assessment $assessment, array $questions): void
    {
        foreach ($questions as $index => $question) {
            $assessment->questions()->create(array_merge(
                Arr::except($question, ['position']),
                [
                    'question_type' => $question['question_type'] ?? 'mcq',
                    'position' => $question['position'] ?? $index,
                ],
            ));
        }
    }
}
