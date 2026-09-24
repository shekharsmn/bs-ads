<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCopyAnalysis extends Model
{
    protected $fillable = ['report_ad_id', 'score', 'issues', 'suggestions'];

    protected $casts = ['issues' => 'array', 'suggestions' => 'array'];

    public function reportAd()
    {
        return $this->belongsTo(ReportAd::class);
    }
}
