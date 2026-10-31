<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformancePulse extends Model
{
    protected $fillable = ['client_id', 'ad_account_id', 'campaign_id', 'scope_key', 'periods', 'local_time', 'include_campaign_breakdown', 'enabled', 'created_by'];

    protected function casts(): array
    {
        return ['periods' => 'array', 'include_campaign_breakdown' => 'boolean', 'enabled' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function adAccount()
    {
        return $this->belongsTo(AdAccount::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function destinations()
    {
        return $this->belongsToMany(NotificationDestination::class, 'performance_pulse_destination');
    }

    public function runs()
    {
        return $this->hasMany(PerformancePulseRun::class);
    }
}
