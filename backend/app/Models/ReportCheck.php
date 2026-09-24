<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportCheck extends Model
{
    protected $fillable = [
        'report_id', 'check_id', 'name', 'section',
        'status', 'severity', 'finding', 'recommendation',
        'evidence', 'metrics', 'impact_score',
    ];

    protected $casts = [
        'evidence' => 'array',
        'metrics' => 'array',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }
}
