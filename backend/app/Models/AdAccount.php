<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\ReportingObjective;
use Illuminate\Database\Eloquent\Model;

class AdAccount extends Model
{
    protected $fillable = ['client_id', 'platform_connection_id', 'platform', 'external_id', 'manager_external_id', 'name', 'currency', 'timezone', 'status', 'reporting_objective', 'monthly_budget', 'last_collected_at', 'last_error'];

    protected $appends = ['effective_reporting_objective'];

    protected function casts(): array
    {
        return ['platform' => Platform::class, 'reporting_objective' => ReportingObjective::class, 'monthly_budget' => 'decimal:4', 'last_collected_at' => 'datetime'];
    }

    public function getEffectiveReportingObjectiveAttribute(): string
    {
        return $this->reporting_objective?->value ?? (string) ($this->client?->objective ?? ReportingObjective::LeadGeneration->value);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function connection()
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function metrics()
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    public function portalScope()
    {
        return $this->hasOne(ClientPortalAccountScope::class);
    }
}
