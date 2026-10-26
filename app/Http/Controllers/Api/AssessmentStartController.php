<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentStart;
use App\Services\QuestionPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentStartController extends Controller
{
    /**
     * R6 pools: draws this attempt's questions on the server. The learner
     * shows only these and sends the start id back with the answers.
     */
    public function store(Request $request, Assessment $assessment, QuestionPool $pool): JsonResponse
    {
        $start = AssessmentStart::query()->create([
            'user_id' => $request->user()->id,
            'assessment_id' => $assessment->id,
            'question_ids' => $pool->draw($assessment),
        ]);

        return response()->json(['data' => [
            'id' => $start->id,
            'question_ids' => array_map('strval', $start->question_ids),
        ]], 201);
    }
}
