<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * R3: the criteria weights of a simulation scorecard must add up to 100.
 */
class ScorecardWeightsTotal implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $total = collect($value)->sum(fn ($row): int => is_array($row) && is_numeric($row['weight'] ?? null) ? (int) $row['weight'] : 0);

        if ($total !== 100) {
            $fail("The scorecard weights must add up to 100 (they add up to {$total}).");
        }
    }
}
