<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Assessment;
use App\Models\Block;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlockQuestionController extends Controller
{
    public function index(Assessment $assessment, Block $block): AnonymousResourceCollection
    {
        return QuestionResource::collection($block->questions()->get());
    }

    public function store(StoreQuestionRequest $request, Assessment $assessment, Block $block): QuestionResource
    {
        $question = $block->questions()->create(array_merge(
            $request->safe()->except('position'),
            [
                'assessment_id' => $assessment->id,
                'position' => $request->validated('position', $block->questions()->count()),
            ],
        ));

        return (new QuestionResource($question))
            ->additional(['message' => 'Question created successfully.']);
    }

    public function show(Assessment $assessment, Block $block, Question $question): QuestionResource
    {
        return new QuestionResource($question);
    }

    public function update(UpdateQuestionRequest $request, Assessment $assessment, Block $block, Question $question): QuestionResource
    {
        $question->fill($request->validated());
        $question->save();

        return (new QuestionResource($question))
            ->additional(['message' => 'Question updated successfully.']);
    }

    public function destroy(Assessment $assessment, Block $block, Question $question): JsonResponse
    {
        $question->delete();

        return response()->json(status: 204);
    }
}
