<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRuleState extends Model
{
    protected $fillable = ['alert_rule_id', 'scope_key', 'failure_count', 'healthy_count', 'currently_breaching', 'last_evaluated_at', 'last_result'];
    protected function casts(): array { return ['currently_breaching' => 'boolean', 'last_evaluated_at' => 'datetime', 'last_result' => 'array']; }
}
