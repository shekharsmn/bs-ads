<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowActivity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'progress_completed' => 'integer',
            'progress_total' => 'integer',
            'progress_failed' => 'integer',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function attempts()
    {
        return $this->hasMany(WorkflowAttempt::class)->orderByDesc('attempt_number');
    }

    public function latestAttempt()
    {
        return $this->hasOne(WorkflowAttempt::class)->latestOfMany('attempt_number');
    }
}
