<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Requests\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Models\Block;
use App\Models\RoadmapNode;
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
            ->with('blocks.questions')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return AssessmentResource::collection($assessments)
            ->additional([
                'meta' => [
                    'status_counts' => $this->statusCounts(),
                ],
            ]);
    }

    public function store(StoreAssessmentRequest $request): AssessmentResource
    {
        $assessment = DB::transaction(function () use ($request): Assessment {
            $assessment = Assessment::create($request->safe()->only(['title', 'description', 'type', 'status', 'scoring_rules', 'usage_analytics']));

            $this->syncBlocks($assessment, $request->validated('blocks'));

            return $assessment;
        });

        return (new AssessmentResource($assessment->load('blocks.questions')))
            ->additional(['message' => 'Assessment created successfully.']);
    }

    public function show(Assessment $assessment): AssessmentResource
    {
        return new AssessmentResource($assessment->load('blocks.questions'));
    }

    public function duplicate(Assessment $assessment): AssessmentResource
    {
        $source = $assessment->load('blocks.questions');

        $copy = DB::transaction(function () use ($source): Assessment {
            $copy = Assessment::create([
                'title' => 'Copy of '.$source->title,
                'description' => $source->description,
                'type' => $source->type,
                'status' => 'draft',
                'scoring_rules' => $source->scoring_rules,
                'usage_analytics' => $this->resetUsageAnalytics(),
            ]);

            foreach ($source->blocks as $block) {
                $copiedBlock = $copy->blocks()->create([
                    'title' => $block->title,
                    'position' => $block->position,
                    'enabled' => $block->enabled,
                    'pool_size' => $block->pool_size,
                ]);

                foreach ($block->questions as $question) {
                    $copiedBlock->questions()->create([
                        'assessment_id' => $copy->id,
                        'question_text' => $question->question_text,
                        'type' => $question->type,
                        'options' => $question->options,
                        'settings' => $question->settings,
                        'difficulty_level' => $question->difficulty_level,
                        'reward' => $question->reward,
                        'required_to_pass_block' => $question->required_to_pass_block,
                        'answer_explanation' => $question->answer_explanation,
                        'position' => $question->position,
                    ]);
                }
            }

            return $copy;
        });

        return (new AssessmentResource($copy->load('blocks.questions')))
            ->additional(['message' => 'Assessment cloned successfully.']);
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): AssessmentResource
    {
        $assessment = DB::transaction(function () use ($request, $assessment): Assessment {
            $assessment->fill($request->safe()->only(['title', 'description', 'type', 'status', 'scoring_rules', 'usage_analytics']));
            $assessment->save();

            if ($request->safe()->has('blocks')) {
                $assessment->blocks()->delete();
                $this->syncBlocks($assessment, $request->validated('blocks'));
            }

            return $assessment;
        });

        return (new AssessmentResource($assessment->load('blocks.questions')))
            ->additional(['message' => 'Assessment updated successfully.']);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        // Roadmap steps reference assessments with restrictOnDelete; say so
        // plainly instead of failing on the foreign key (MG3 delete pop-up).
        $roadmaps = RoadmapNode::query()->where('assessment_id', $assessment->id)->distinct()->count('roadmap_id');
        abort_if($roadmaps > 0, 422, "It's used in {$roadmaps} roadmap".($roadmaps === 1 ? '' : 's').'. Remove it from there first.');

        $assessment->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function syncBlocks(Assessment $assessment, array $blocks): void
    {
        foreach ($blocks as $index => $block) {
            $assessmentBlock = $assessment->blocks()->create([
                'title' => $block['title'],
                'position' => $block['position'] ?? $index,
                'enabled' => $block['enabled'] ?? true,
                'pool_size' => $block['pool_size'] ?? null,
            ]);

            $this->syncQuestions($assessment, $assessmentBlock, $block['questions']);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function resetUsageAnalytics(): array
    {
        return [
            'times_used' => 0,
            'completion_rate_percent' => 0,
            'average_score_percent' => 0,
            'completed_attempts' => 0,
            'started_attempts' => 0,
            'last_attempted_at' => null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = Assessment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return array_merge([
            'draft' => 0,
            'published' => 0,
            'archived' => 0,
        ], $counts);
    }
}
