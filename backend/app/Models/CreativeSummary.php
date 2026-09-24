<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreativeSummary extends Model
{
    protected $fillable = [
        'report_id', 'format_counts', 'format_spend',
        'format_leads', 'cta_distribution', 'common_issues',
    ];

    protected $casts = [
        'format_counts' => 'array',
        'format_spend' => 'array',
        'format_leads' => 'array',
        'cta_distribution' => 'array',
        'common_issues' => 'array',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }
}
