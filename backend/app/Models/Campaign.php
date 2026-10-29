<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $fillable = ['ad_account_id', 'external_id', 'name', 'status', 'type', 'daily_budget', 'lifetime_budget'];
    protected function casts(): array { return ['daily_budget' => 'decimal:4', 'lifetime_budget' => 'decimal:4']; }
    public function adAccount() { return $this->belongsTo(AdAccount::class); }
    public function metrics() { return $this->hasMany(MetricSnapshot::class); }
    public function adGroups() { return $this->hasMany(AdGroup::class); }
}
