<?php

use App\Http\Controllers\Api\AssessmentBlockController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\BlockQuestionController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\SimulationTestSessionController;
use App\Http\Controllers\Api\SimulationTestSessionMessageController;
use Illuminate\Support\Facades\Route;

Route::post('assessments/{assessment}/clone', [AssessmentController::class, 'duplicate']);
Route::apiResource('assessments', AssessmentController::class);
Route::apiResource('assessments.blocks', AssessmentBlockController::class)
    ->scoped();
Route::apiResource('assessments.blocks.questions', BlockQuestionController::class)
    ->scoped();

Route::apiResource('simulations', SimulationController::class);
Route::post('simulations/{simulation}/test-sessions', [SimulationTestSessionController::class, 'store']);
Route::post('simulation-test-sessions/{session}/messages', [SimulationTestSessionMessageController::class, 'store']);

Route::apiResource('materials', MaterialController::class);
