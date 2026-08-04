<?php

use App\Http\Controllers\Api\AssessmentBlockController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\BlockQuestionController;
use Illuminate\Support\Facades\Route;

Route::post('assessments/{assessment}/clone', [AssessmentController::class, 'duplicate']);
Route::apiResource('assessments', AssessmentController::class);
Route::apiResource('assessments.blocks', AssessmentBlockController::class)
    ->scoped();
Route::apiResource('assessments.blocks.questions', BlockQuestionController::class)
    ->scoped();
