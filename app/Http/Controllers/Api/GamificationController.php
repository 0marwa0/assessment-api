<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\PracticeSession;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\XpAward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GamificationController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = PracticeSession::query()->where('user_id', $user->id);

        $avgScore = (clone $sessions)->whereNotNull('score')->avg('score');

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'xp_total' => $this->totalXp($user->id),
                'current_streak' => $this->currentStreak($user->id),
                'avg_deal_score' => $avgScore !== null ? (int) round($avgScore) : null,
                'total_roleplays_completed' => (clone $sessions)->count(),
                'total_practice_seconds' => (int) (clone $sessions)->sum('duration_seconds'),
            ],
        ]);
    }

    /**
     * Aggregates the broker's own AI-graded call scorecards into a per-skill
     * mastery breakdown — real pass rates from their actual practice history,
     * not a fixed demo array.
     */
    public function competencies(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = PracticeSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ai_report')
            ->get(['id', 'ai_report']);

        /** @var array<string, array{passed: int, total: int}> $tally */
        $tally = [];

        foreach ($sessions as $session) {
            foreach (($session->ai_report['scorecard'] ?? []) as $category) {
                $name = $category['name'] ?? null;
                $items = $category['items'] ?? [];

                if (! is_string($name) || ! is_array($items) || $items === []) {
                    continue;
                }

                $tally[$name] ??= ['passed' => 0, 'total' => 0];

                foreach ($items as $item) {
                    $tally[$name]['total']++;
                    if (! empty($item['passed'])) {
                        $tally[$name]['passed']++;
                    }
                }
            }
        }

        $competencies = collect($tally)
            ->map(fn (array $counts, string $name): array => [
                'label' => $name,
                'passed' => $counts['passed'],
                'total' => $counts['total'],
                'pct' => $counts['total'] > 0 ? (int) round(($counts['passed'] / $counts['total']) * 100) : 0,
            ])
            ->values();

        return response()->json([
            'data' => [
                'competencies' => $competencies,
                'sessions_considered' => $sessions->count(),
            ],
        ]);
    }

    /**
     * ?period=week ranks by XP earned in the last 7 days (Home shows "This
     * week"); anything else ranks by all-time XP.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $since = $request->query('period') === 'week' ? now()->subDays(7) : null;

        $rows = User::query()
            ->role(UserRole::EndUser->value)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'user_id' => $user->id,
                'name' => $user->name,
                'xp_total' => $this->totalXp($user->id, $since),
            ])
            ->sortByDesc('xp_total')
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    private function totalXp(int $userId, ?\DateTimeInterface $since = null): int
    {
        return (int) XpAward::query()
            ->where('user_id', $userId)
            ->when($since !== null, fn ($query) => $query->where('awarded_at', '>=', $since))
            ->sum('amount');
    }

    /** Also used by the manager's Activity Log. */
    public function currentStreak(int $userId): int
    {
        $dates = UserActivityLog::query()
            ->where('user_id', $userId)
            ->orderByDesc('activity_date')
            ->pluck('activity_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString());

        if ($dates->isEmpty()) {
            return 0;
        }

        $today = Carbon::today();
        $mostRecent = Carbon::parse($dates->first());

        // Streak is broken once the most recent activity is older than yesterday.
        if ($mostRecent->lt($today->copy()->subDay())) {
            return 0;
        }

        $streak = 0;
        $cursor = $mostRecent->copy();

        foreach ($dates as $date) {
            if (Carbon::parse($date)->equalTo($cursor)) {
                $streak++;
                $cursor->subDay();
            } else {
                break;
            }
        }

        return $streak;
    }
}
