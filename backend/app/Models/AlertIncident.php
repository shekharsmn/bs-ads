<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AlertIncident extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'client_id', 'alert_rule_id', 'ad_account_id', 'campaign_id', 'assigned_to', 'incident_key', 'status', 'severity', 'title', 'summary', 'observed_value', 'baseline_value', 'threshold_value', 'triggered_at', 'acknowledged_at', 'snoozed_until', 'resolved_at', 'last_notified_at'];
    protected function casts(): array { return ['triggered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'snoozed_until' => 'datetime', 'resolved_at' => 'datetime', 'last_notified_at' => 'datetime']; }
    public function client() { return $this->belongsTo(Client::class); }
    public function rule() { return $this->belongsTo(AlertRule::class, 'alert_rule_id'); }
    public function adAccount() { return $this->belongsTo(AdAccount::class); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function events() { return $this->hasMany(AlertEvent::class); }
}
