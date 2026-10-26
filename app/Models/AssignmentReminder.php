<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// R12: one row per reminder sent (due_soon / overdue) so the daily command is idempotent.
#[Fillable(['assignment_type', 'assignment_id', 'kind', 'sent_at'])]
class AssignmentReminder extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['assignment_id' => 'integer', 'sent_at' => 'datetime'];
    }
}
