<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDestination extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['configuration'];

    protected $appends = ['configured'];

    protected $casts = ['configuration' => 'encrypted:array', 'enabled' => 'boolean', 'last_tested_at' => 'datetime'];

    public function subscriptions()
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function performancePulses()
    {
        return $this->belongsToMany(PerformancePulse::class, 'performance_pulse_destination');
    }

    public function getConfiguredAttribute(): bool
    {
        return filled($this->configuration);
    }
}
