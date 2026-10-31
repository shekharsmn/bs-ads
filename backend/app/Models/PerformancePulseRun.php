<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformancePulseRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reporting_date' => 'date', 'rendered_payload' => 'array', 'freshness_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function pulse()
    {
        return $this->belongsTo(PerformancePulse::class, 'performance_pulse_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
