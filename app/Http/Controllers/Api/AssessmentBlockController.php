<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlockRequest;
use App\Http\Requests\UpdateBlockRequest;
use App\Http\Resources\BlockResource;
use App\Models\Assessment;
use App\Models\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AssessmentBlockController extends Controller
{
    public function index(Assessment $assessment): AnonymousResourceCollection
    {
        return BlockResource::collection($assessment->blocks()->with('questions')->get());
    }

    public function store(StoreBlockRequest $request, Assessment $assessment): BlockResource
    {
        $block = DB::transaction(function () use ($request, $assessment): Block {
            $block = $assessment->blocks()->create([
                'title' => $request->validated('title'),
                'position' => $request->validated('position', $assessment->blocks()->count()),
            ]);

            $this->syncQuestions($assessment, $block, $request->validated('questions', []));

            return $block;
        });

        return (new BlockResource($block->load('questions')))
            ->additional(['message' => 'Block created successfully.']);
    }

    public function show(Assessment $assessment, Block $block): BlockResource
    {
        return new BlockResource($block->load('questions'));
    }

    public function update(UpdateBlockRequest $request, Assessment $assessment, Block $block): BlockResource
    {
        DB::transaction(function () use ($request, $assessment, $block): void {
            $block->fill($request->safe()->only(['title', 'position']));
            $block->save();

            if ($request->safe()->has('questions')) {
                $block->questions()->delete();
                $this->syncQuestions($assessment, $block, $request->validated('questions'));
            }
        });

        return (new BlockResource($block->load('questions')))
            ->additional(['message' => 'Block updated successfully.']);
    }

    public function destroy(Assessment $assessment, Block $block): JsonResponse
    {
        $block->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function syncQuestions(Assessment $assessment, Block $block, array $questions): void
    {
        foreach ($questions as $index => $question) {
            $block->questions()->create(array_merge(
                Arr::except($question, ['position']),
                [
                    'assessment_id' => $assessment->id,
                    'position' => $question['position'] ?? $index,
                ],
            ));
        }
    }
}
