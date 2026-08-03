<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionController;
use Illuminate\Support\Facades\Route;

Route::apiResource('assessments', AssessmentController::class);
Route::apiResource('assessments.questions', AssessmentQuestionController::class)
    ->scoped();
