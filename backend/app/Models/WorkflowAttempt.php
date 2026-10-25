<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function activity()
    {
        return $this->belongsTo(WorkflowActivity::class, 'workflow_activity_id');
    }
}
