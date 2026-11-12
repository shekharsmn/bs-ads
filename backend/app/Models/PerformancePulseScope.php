<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformancePulseScope extends Model
{
    protected $fillable = ['performance_pulse_id', 'campaign_id', 'scope_key'];

    public function pulse()
    {
        return $this->belongsTo(PerformancePulse::class, 'performance_pulse_id');
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
