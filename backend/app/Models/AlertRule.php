<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = ['alert_template_id', 'client_id', 'ad_account_id', 'campaign_id', 'name', 'metric', 'operator', 'threshold', 'severity', 'window_days', 'minimum_spend', 'minimum_impressions', 'config', 'enabled'];
    protected function casts(): array { return ['threshold' => 'decimal:6', 'minimum_spend' => 'decimal:6', 'config' => 'array', 'enabled' => 'boolean']; }
    public function client() { return $this->belongsTo(Client::class); }
    public function adAccount() { return $this->belongsTo(AdAccount::class); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
    public function template() { return $this->belongsTo(AlertTemplate::class, 'alert_template_id'); }
    public function states() { return $this->hasMany(AlertRuleState::class); }
}
