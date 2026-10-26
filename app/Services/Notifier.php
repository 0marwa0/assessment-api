<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentAttempt;
use App\Models\Certificate;
use App\Models\PracticeSession;
use App\Models\PracticeSessionRereview;
use App\Models\RoadmapAssignment;
use App\Models\SimulationAssignment;
use App\Models\User;
use App\Notifications\AcademyNotification;
use Carbon\CarbonInterface;
use Throwable;

/**
 * R12: builds and sends the Academy notifications (bell + email) for each
 * event. Sending never breaks the request that triggered it: failures (e.g.
 * a mail server being down) are reported and swallowed.
 */
class Notifier
{
    public static function send(?User $user, AcademyNotification $notification): void
    {
        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function roadmapAssigned(RoadmapAssignment $assignment, User $manager): void
    {
        $assignment->loadMissing(['roadmap.nodes', 'user']);
        $roadmap = $assignment->roadmap;
        $title = $roadmap?->title ?? 'Roadmap';
        $steps = $roadmap?->nodes?->count() ?? 0;
        $due = self::date($assignment->due_at);

        self::send($assignment->user, new AcademyNotification(
            kind: 'assignment',
            title: 'New roadmap:',
            text: $title,
            meta: 'From '.$manager->name.($due ? " · due {$due}" : ''),
            link: '/roadmap/'.$assignment->roadmap_id,
            email: [
                'subject' => "{$manager->name} assigned you “{$title}”",
                'heading' => 'Hi '.self::firstName($assignment->user).', you have a new roadmap',
                'card_title' => $title,
                'card_text' => $steps.' '.($steps === 1 ? 'step' : 'steps').($due ? " · due {$due}" : ''),
            ],
        ));
    }

    public static function assessmentAssigned(AssessmentAssignment $assignment, User $manager): void
    {
        $assignment->loadMissing(['assessment', 'user']);
        $title = $assignment->assessment?->title ?? 'Assessment';
        $due = self::date($assignment->due_at);

        self::send($assignment->user, new AcademyNotification(
            kind: 'assignment',
            title: 'New assessment:',
            text: $title,
            meta: 'From '.$manager->name.($due ? " · due {$due}" : ''),
            link: '/home',
            email: [
                'subject' => "{$manager->name} assigned you “{$title}”",
                'heading' => 'Hi '.self::firstName($assignment->user).', you have a new assessment',
                'card_title' => $title,
                'card_text' => $due ? "Due {$due}" : 'No due date',
            ],
        ));
    }

    public static function simulationAssigned(SimulationAssignment $assignment, User $manager): void
    {
        $assignment->loadMissing(['simulation', 'user']);
        $title = $assignment->simulation?->title ?? 'Practice call';
        $due = self::date($assignment->due_at);
        $target = $assignment->min_score !== null ? "Score {$assignment->min_score}+ to finish" : 'Finish one call';

        self::send($assignment->user, new AcademyNotification(
            kind: 'assignment',
            title: 'New practice call:',
            text: $title,
            meta: 'From '.$manager->name.($due ? " · due {$due}" : ''),
            link: '/practice?sim='.$assignment->simulation_id,
            email: [
                'subject' => "{$manager->name} assigned you “{$title}”",
                'heading' => 'Hi '.self::firstName($assignment->user).', you have a new practice call',
                'card_title' => $title,
                'card_text' => $target.($due ? " · due {$due}" : ''),
            ],
        ));
    }

    public static function reviewSent(PracticeSession $session, User $manager, int $finalScore): void
    {
        $session->loadMissing('user');
        $call = $session->simulation_title ?: 'practice call';
        $ai = $session->ai_report['score'] ?? null;
        $score = is_numeric($ai) && (int) $ai !== $finalScore ? 'Score '.(int) $ai.' → '.$finalScore : "Score {$finalScore}";

        self::send($session->user, new AcademyNotification(
            kind: 'review',
            title: "{$manager->name} reviewed",
            text: "your call {$call}",
            meta: $score,
            link: '/practice/report/'.$session->id,
            email: [
                'subject' => "{$manager->name} reviewed your call",
                'heading' => 'Hi '.self::firstName($session->user).', your review is ready',
                'card_title' => $call,
                'card_text' => $score,
                'button' => 'See the review',
            ],
        ));
    }

    public static function rereviewAnswered(PracticeSessionRereview $rereview, User $manager): void
    {
        $session = $rereview->practiceSession;
        $session?->loadMissing('user');
        $call = $session?->simulation_title ?: 'practice call';
        $score = $session?->score;
        $meta = $rereview->decision === 'changed'
            ? 'Score changed'.($score !== null ? " to {$score}" : '')
            : 'Score kept'.($score !== null ? " at {$score}" : '');

        self::send($session?->user, new AcademyNotification(
            kind: 'review',
            title: "{$manager->name} answered",
            text: "your re-review {$call}",
            meta: $meta,
            link: '/practice/report/'.$rereview->practice_session_id,
            email: [
                'subject' => "{$manager->name} answered your re-review",
                'heading' => 'Hi '.self::firstName($session?->user).', your re-review was answered',
                'card_title' => $call,
                'card_text' => $meta.(filled($rereview->reply) ? ' · “'.str($rereview->reply)->limit(140).'”' : ''),
                'button' => 'See the answer',
            ],
        ));
    }

    public static function rereviewRequested(PracticeSessionRereview $rereview): void
    {
        $rereview->loadMissing(['user', 'practiceSession']);
        $broker = $rereview->user?->name ?? 'A broker';
        $call = $rereview->practiceSession?->simulation_title ?: 'practice call';
        $quote = '“'.str($rereview->message)->limit(80).'”';

        User::role(UserRole::Manager->value)->get()->each(fn (User $manager) => self::send($manager, new AcademyNotification(
            kind: 'rereview',
            title: "{$broker} asked for a re-review",
            text: $call,
            meta: $quote,
            link: '/review',
            email: [
                'subject' => "{$broker} asked for a re-review",
                'heading' => "{$broker} asked you to look at a score again",
                'card_title' => $call.($rereview->criterion ? " · {$rereview->criterion}" : ''),
                'card_text' => $quote,
                'button' => 'Open Review Queue',
            ],
        )));
    }

    /**
     * R4: every question of a test attempt now has a score (roleplay call
     * scored, written answers graded), so the result is final.
     */
    public static function attemptFinal(AssessmentAttempt $attempt): void
    {
        $attempt->loadMissing(['user', 'assessment']);
        $title = $attempt->assessment?->title ?? 'Assessment';
        $outcome = $attempt->passed ? 'Passed' : 'Not passed';

        self::send($attempt->user, new AcademyNotification(
            kind: 'result',
            title: 'Your test result is final:',
            text: "{$title} · {$attempt->score_pct}%",
            meta: $outcome,
            link: '/results/'.$attempt->id,
            email: [
                'subject' => "Your test result is final: {$title}",
                'heading' => 'Hi '.self::firstName($attempt->user).', your test result is final',
                'card_title' => $title,
                'card_text' => "{$attempt->score_pct}% · {$outcome}",
                'button' => 'See your result',
            ],
        ));
    }

    public static function certificateEarned(Certificate $certificate): void
    {
        $certificate->loadMissing(['user', 'assessment']);
        $title = $certificate->assessment?->title ?? 'Assessment';
        $tier = $certificate->tier ?: 'Certified';

        self::send($certificate->user, new AcademyNotification(
            kind: 'certificate',
            title: 'Certificate earned:',
            text: $tier,
            meta: $title,
            link: '/certificates',
            email: [
                'subject' => "You earned a certificate: {$title}",
                'heading' => 'Well done '.self::firstName($certificate->user).', you earned a certificate',
                'card_title' => $title,
                'card_text' => "{$tier} · {$certificate->score_pct}%",
                'button' => 'See your certificate',
            ],
        ));
    }

    /**
     * @param  array{type: string, title: string, link: string, due_at: ?CarbonInterface}  $item
     */
    public static function nudge(User $broker, User $manager, array $item, string $message, string $status, bool $viaApp, bool $viaEmail): void
    {
        $meta = $item['title'].' '.$status;

        self::send($broker, new AcademyNotification(
            kind: 'nudge',
            title: "Nudge from {$manager->name}:",
            text: "“{$message}”",
            meta: $meta,
            link: $item['link'],
            email: [
                'subject' => "{$manager->name} sent you a nudge",
                'heading' => 'Hi '.self::firstName($broker).', a nudge from '.$manager->name,
                'card_title' => $item['title'],
                'card_text' => "“{$message}”",
            ],
            viaApp: $viaApp,
            viaEmail: $viaEmail,
        ));
    }

    /**
     * Daily reminder: $kind is due_soon or overdue.
     *
     * @param  array{type: string, title: string, link: string, due_at: ?CarbonInterface}  $item
     */
    public static function dueReminder(User $broker, array $item, string $kind): void
    {
        $due = self::date($item['due_at']);
        $overdue = $kind === 'overdue';

        self::send($broker, new AcademyNotification(
            kind: $overdue ? 'overdue' : 'due_soon',
            title: $overdue ? 'Overdue:' : 'Due tomorrow:',
            text: $item['title'],
            meta: $overdue ? "Was due {$due}" : "Due {$due}",
            link: $item['link'],
            email: [
                'subject' => $overdue ? "“{$item['title']}” is overdue" : "“{$item['title']}” is due tomorrow",
                'heading' => 'Hi '.self::firstName($broker).($overdue ? ', this is overdue' : ', this is due tomorrow'),
                'card_title' => $item['title'],
                'card_text' => $overdue ? "Was due {$due}" : "Due {$due}",
            ],
        ));
    }

    private static function firstName(?User $user): string
    {
        return explode(' ', trim((string) $user?->name))[0] ?: 'there';
    }

    private static function date(?CarbonInterface $at): ?string
    {
        return $at?->format('M j');
    }
}
