<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdVideoMetric extends Model
{
    protected $fillable = [
        'report_ad_id', 'hook_rate', 'thruplay_rate', 'avg_watch_time', 'duration',
    ];

    public function reportAd()
    {
        return $this->belongsTo(ReportAd::class);
    }
}
