<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAd extends Model
{
    protected $fillable = [
        'report_id', 'meta_ad_id', 'ad_name', 'format',
        'spend', 'impressions', 'ctr', 'cpc', 'frequency', 'leads',
        'perf_flags', 'copy',
    ];

    protected $casts = [
        'perf_flags' => 'array',
        'copy' => 'array',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function copyAnalysis()
    {
        return $this->hasOne(AdCopyAnalysis::class);
    }

    public function videoMetrics()
    {
        return $this->hasOne(AdVideoMetric::class);
    }

    public function visualAnalysis()
    {
        return $this->hasOne(AdVisualAnalysis::class);
    }
}
