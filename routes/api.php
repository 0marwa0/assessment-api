<?php

use App\Http\Controllers\Api\AssessmentAssignmentController;
use App\Http\Controllers\Api\AssessmentAttemptController;
use App\Http\Controllers\Api\AssessmentQuestionDraftController;
use App\Http\Controllers\Api\AssessmentStartController;
use App\Http\Controllers\Api\AssessmentBlockController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentUsageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockQuestionController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\GamificationController;
use App\Http\Controllers\Api\LearnerActivityController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NudgeController;
use App\Http\Controllers\Api\PracticeSessionController;
use App\Http\Controllers\Api\PracticeSessionReviewController;
use App\Http\Controllers\Api\RoadmapAssignmentController;
use App\Http\Controllers\Api\SimulationAssignmentController;
use App\Http\Controllers\Api\RoadmapController;
use App\Http\Controllers\Api\RoadmapNodeProgressController;
use App\Http\Controllers\Api\SimulationController;
use App\Http\Controllers\Api\SimulationInviteController;
use App\Http\Controllers\Api\SimulationScorecardDraftController;
use App\Http\Controllers\Api\SimulationTestSessionController;
use App\Http\Controllers\Api\SimulationTestSessionMessageController;
use App\Http\Controllers\Api\TeamOverviewController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WrittenAnswerController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
// R11: forgot / reset password. Throttled per IP to blunt email probing.
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // R12: the bell (own notifications only) and the email preference.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
    Route::patch('notification-settings', [NotificationController::class, 'updateSettings']);

    Route::get('roadmap-assignments', [RoadmapAssignmentController::class, 'index']);
    Route::get('assessment-assignments', [AssessmentAssignmentController::class, 'index']);
    Route::get('simulation-assignments', [SimulationAssignmentController::class, 'index']);

    Route::get('roadmap-node-progress', [RoadmapNodeProgressController::class, 'index']);
    Route::patch('roadmap-nodes/{roadmap_node}/progress', [RoadmapNodeProgressController::class, 'update']);

    Route::get('gamification/summary', [GamificationController::class, 'summary']);
    Route::get('gamification/competencies', [GamificationController::class, 'competencies']);
    Route::get('leaderboard', [GamificationController::class, 'leaderboard']);

    // Requires auth (rather than sitting in the public apiResource below) so
    // the session can be attached to the broker who actually ran the call.
    Route::post('practice-sessions', [PracticeSessionController::class, 'store']);
    // Brokers see only their own calls; managers see everyone's (Review Queue).
    Route::get('practice-sessions', [PracticeSessionController::class, 'index']);
    Route::post('practice-sessions/{practice_session}/ai-report', [PracticeSessionController::class, 'generateAiReport']);
    // MG2: owner or manager reads the manager review; the owner asks for a re-review.
    Route::get('practice-sessions/{practice_session}/review', [PracticeSessionReviewController::class, 'show']);
    Route::post('practice-sessions/{practice_session}/rereview', [PracticeSessionReviewController::class, 'requestRereview']);
    Route::post('assessment-attempts', [AssessmentAttemptController::class, 'store']);
    // A past result: owner or manager (same shape as the submit response).
    Route::get('assessment-attempts/{attempt}', [AssessmentAttemptController::class, 'show']);
    // R6 pools: draw this attempt's questions before answering.
    Route::post('assessments/{assessment}/start', [AssessmentStartController::class, 'store']);
    // R9: own certificates (managers may pass ?user_id=).
    Route::get('certificates', [CertificateController::class, 'index']);

    // Anyone logged in can read an assessment; learners get it without the
    // correct answers (see QuestionResource). Only managers can change one.
    Route::apiResource('assessments', AssessmentController::class)->only(['index', 'show']);

    // R1b: content is readable by anyone logged in (brokers need simulations
    // for Practice, materials and roadmaps for their roadmap); only managers
    // can create, change or delete it (manager group below).
    Route::apiResource('simulations', SimulationController::class)->only(['index', 'show']);
    Route::apiResource('materials', MaterialController::class)->only(['index', 'show']);
    Route::apiResource('roadmaps', RoadmapController::class)->only(['index', 'show']);

    Route::middleware('role:manager')->group(function (): void {
        Route::get('users', [UserController::class, 'index']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole']);
        Route::get('users/{user}/activity-log', [LearnerActivityController::class, 'show']);
        // R12: Coaching Hub "Nudge" (one per broker per item per day).
        Route::get('nudges', [NudgeController::class, 'index']);
        Route::post('users/{user}/nudge', [NudgeController::class, 'store']);
        Route::get('team-overview', [TeamOverviewController::class, 'index']);

        Route::patch('practice-sessions/{practice_session}', [PracticeSessionController::class, 'update']);

        // MG2 Review Queue
        Route::get('review-queue', [PracticeSessionReviewController::class, 'queue']);
        // R4: written answers to grade / roleplay calls awaiting a score.
        Route::get('written-answers', [WrittenAnswerController::class, 'index']);
        Route::post('assessment-attempts/{attempt}/questions/{questionId}/grade', [WrittenAnswerController::class, 'grade']);
        Route::post('practice-sessions/{practice_session}/review', [PracticeSessionReviewController::class, 'store']);
        Route::post('practice-sessions/{practice_session}/comments', [PracticeSessionReviewController::class, 'comment']);
        Route::post('practice-session-rereviews/{rereview}/answer', [PracticeSessionReviewController::class, 'answer']);

        Route::post('assessments/{assessment}/clone', [AssessmentController::class, 'duplicate']);
        // MG3 / R2: real usage numbers from graded attempts.
        Route::get('assessment-usage', [AssessmentUsageController::class, 'index']);
        Route::get('assessments/{assessment}/usage', [AssessmentUsageController::class, 'show']);
        // R5: AI draft questions from Material Bank items (nothing is saved).
        Route::post('assessment-question-drafts', [AssessmentQuestionDraftController::class, 'store']);
        Route::apiResource('assessments', AssessmentController::class)->except(['index', 'show']);
        Route::apiResource('assessments.blocks', AssessmentBlockController::class)
            ->scoped();
        Route::apiResource('assessments.blocks.questions', BlockQuestionController::class)
            ->scoped();

        Route::post('roadmaps', [RoadmapController::class, 'store']);
        Route::match(['put', 'patch'], 'roadmaps/{roadmap}', [RoadmapController::class, 'update']);
        Route::delete('roadmaps/{roadmap}', [RoadmapController::class, 'destroy']);

        Route::apiResource('simulations', SimulationController::class)->except(['index', 'show']);
        Route::post('simulations/{simulation}/test-sessions', [SimulationTestSessionController::class, 'store']);
        Route::post('simulation-test-sessions/{session}/messages', [SimulationTestSessionMessageController::class, 'store']);
        // R3: "Draft with AI" weighted scorecard (unsaved editor or a saved simulation).
        Route::post('simulations/scorecard-draft', [SimulationScorecardDraftController::class, 'store']);
        Route::post('simulations/{simulation}/scorecard-draft', [SimulationScorecardDraftController::class, 'store']);

        Route::apiResource('materials', MaterialController::class)->except(['index', 'show']);
        Route::post('uploads', [UploadController::class, 'store']);

        Route::post('roadmap-assignments', [RoadmapAssignmentController::class, 'store']);
        Route::post('assessment-assignments', [AssessmentAssignmentController::class, 'store']);
        Route::post('simulation-assignments', [SimulationAssignmentController::class, 'store']);

        Route::get('simulations/{simulation}/invites', [SimulationInviteController::class, 'index']);
        Route::post('simulations/{simulation}/invites', [SimulationInviteController::class, 'store']);
    });
});

// Guest invite-link flow — a manager sends a candidate a link to a single
// simulation; the candidate proves they're the intended recipient by typing
// the email the manager invited, no account required. Throttled on the
// email-checking steps to blunt brute-force guessing of the gate.
Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('simulation-invites/{token}', [SimulationInviteController::class, 'show']);
    // R9: public certificate verification by its short code.
    Route::get('certificates/{code}', [CertificateController::class, 'show']);
    Route::post('simulation-invites/{token}/unlock', [SimulationInviteController::class, 'unlock']);
    Route::post('simulation-invites/{token}/practice-sessions', [SimulationInviteController::class, 'completeSession']);
});



