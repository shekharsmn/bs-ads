<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdVisualAnalysis extends Model
{
    protected $fillable = [
        'report_ad_id', 'type', 'analysis_text', 'transcript', 'has_frames', 'error',
    ];

    public function reportAd()
    {
        return $this->belongsTo(ReportAd::class);
    }
}
