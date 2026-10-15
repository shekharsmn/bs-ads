<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetricSnapshot extends Model
{
    protected $fillable = ['ad_account_id', 'campaign_id', 'scope', 'scope_key', 'metric_date', 'currency', 'spend', 'impressions', 'reach', 'clicks', 'conversions', 'leads', 'purchases', 'conversion_value', 'mapping_version', 'collected_at'];
    protected function casts(): array
    {
        return ['metric_date' => 'date', 'collected_at' => 'datetime', 'spend' => 'decimal:6', 'conversions' => 'decimal:6', 'leads' => 'decimal:6', 'purchases' => 'decimal:6', 'conversion_value' => 'decimal:6'];
    }
    public function adAccount() { return $this->belongsTo(AdAccount::class); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
}
