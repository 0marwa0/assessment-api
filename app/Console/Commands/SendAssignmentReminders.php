<?php

namespace App\Console\Commands;

use App\Models\AssessmentAssignment;
use App\Models\AssignmentReminder;
use App\Models\RoadmapAssignment;
use App\Models\SimulationAssignment;
use App\Services\AssignmentItems;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * R12: daily "Due tomorrow" (due within 24h) and "Overdue" reminders for
 * unfinished roadmap, assessment and simulation assignments. Idempotent: each
 * assignment gets at most one reminder of each kind, ever.
 */
class SendAssignmentReminders extends Command
{
    protected $signature = 'academy:send-reminders';

    protected $description = 'Notify brokers about assignments due within 24 hours or overdue (once per assignment per kind).';

    public function handle(): int
    {
        $sent = 0;

        $query = fn (string $class) => $class::query()
            ->with(match ($class) {
                RoadmapAssignment::class => ['roadmap', 'user'],
                SimulationAssignment::class => ['simulation', 'user'],
                default => ['assessment', 'user'],
            })
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDay())
            ->orderBy('id');

        foreach ([RoadmapAssignment::class, AssessmentAssignment::class, SimulationAssignment::class] as $class) {
            $query($class)->chunkById(200, function ($assignments) use (&$sent): void {
                foreach ($assignments as $assignment) {
                    $sent += $this->remind($assignment) ? 1 : 0;
                }
            });
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    private function remind(RoadmapAssignment|AssessmentAssignment|SimulationAssignment $assignment): bool
    {
        $kind = $assignment->due_at->isPast() ? 'overdue' : 'due_soon';
        $type = match (true) {
            $assignment instanceof RoadmapAssignment => 'roadmap',
            $assignment instanceof SimulationAssignment => 'simulation',
            default => 'assessment',
        };

        $already = AssignmentReminder::query()
            ->where('assignment_type', $type)
            ->where('assignment_id', $assignment->id)
            ->where('kind', $kind)
            ->exists();

        if ($already || $assignment->user === null || AssignmentItems::isDone($assignment)) {
            return false;
        }

        try {
            AssignmentReminder::create([
                'assignment_type' => $type,
                'assignment_id' => $assignment->id,
                'kind' => $kind,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // another run got there first
        }

        Notifier::dueReminder($assignment->user, AssignmentItems::describe($assignment), $kind);

        return true;
    }
}
