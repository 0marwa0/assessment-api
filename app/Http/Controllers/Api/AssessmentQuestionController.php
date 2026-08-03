<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Assessment;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssessmentQuestionController extends Controller
{
    public function index(Assessment $assessment): AnonymousResourceCollection
    {
        return QuestionResource::collection($assessment->questions()->get());
    }

    public function store(StoreQuestionRequest $request, Assessment $assessment): QuestionResource
    {
        $question = $assessment->questions()->create(array_merge(
            $request->safe()->except('position'),
            [
                'question_type' => $request->validated('question_type', 'mcq'),
                'position' => $request->validated('position', $assessment->questions()->count()),
            ],
        ));

        return (new QuestionResource($question))
            ->additional(['message' => 'Question created successfully.']);
    }

    public function show(Assessment $assessment, Question $question): QuestionResource
    {
        return new QuestionResource($question);
    }

    public function update(UpdateQuestionRequest $request, Assessment $assessment, Question $question): QuestionResource
    {
        $question->fill($request->validated());
        $question->save();

        return (new QuestionResource($question))
            ->additional(['message' => 'Question updated successfully.']);
    }

    public function destroy(Assessment $assessment, Question $question): JsonResponse
    {
        $question->delete();

        return response()->json(status: 204);
    }
}
