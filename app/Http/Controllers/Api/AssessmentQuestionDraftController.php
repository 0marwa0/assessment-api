<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionDraftsRequest;
use App\Models\Material;
use App\Services\QuestionDraftGenerator;
use Illuminate\Http\JsonResponse;

class AssessmentQuestionDraftController extends Controller
{
    /**
     * R5 "Generate questions from a material": AI drafts for the manager to
     * review in the builder. Nothing is saved here.
     */
    public function store(StoreQuestionDraftsRequest $request, QuestionDraftGenerator $generator): JsonResponse
    {
        $materials = Material::query()->whereIn('id', $request->validated('material_ids'))->get();

        $drafts = $generator->generate(
            $materials,
            $request->integer('count'),
            array_values($request->validated('types')),
            (string) $request->validated('difficulty'),
        );

        return response()->json(['data' => $drafts]);
    }
}
