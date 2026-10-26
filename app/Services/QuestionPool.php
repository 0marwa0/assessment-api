<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Block;

/**
 * R6 question pools: a block can show only `pool_size` random questions per
 * attempt. The draw is made on the server when the attempt starts and stored
 * (AssessmentStart), so grading uses exactly the questions the learner saw.
 */
class QuestionPool
{
    public function hasPools(Assessment $assessment): bool
    {
        $assessment->loadMissing('blocks.questions');

        return $assessment->blocks->contains(fn (Block $block): bool => $this->isPooled($block));
    }

    /**
     * Question ids for one attempt: every graded question of a normal block,
     * and `pool_size` random ones of a pooled block (switched-off blocks skipped).
     *
     * @return list<int>
     */
    public function draw(Assessment $assessment): array
    {
        $assessment->loadMissing('blocks.questions');
        $ids = [];

        foreach ($assessment->blocks as $block) {
            if ($block->enabled === false) {
                continue;
            }
            $blockIds = $block->questions
                ->filter(fn ($q) => QuestionTypes::isGraded($q->type))
                ->pluck('id')->map(fn ($id) => (int) $id)->values();

            if ($this->isPooled($block)) {
                $blockIds = $blockIds->shuffle()->take((int) $block->pool_size)->values();
            }
            array_push($ids, ...$blockIds->all());
        }

        return $ids;
    }

    private function isPooled(Block $block): bool
    {
        if ($block->enabled === false || $block->pool_size === null) {
            return false;
        }

        return $block->pool_size < $block->questions->filter(fn ($q) => QuestionTypes::isGraded($q->type))->count();
    }
}
