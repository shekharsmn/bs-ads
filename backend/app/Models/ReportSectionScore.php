<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSectionScore extends Model
{
    protected $fillable = ['report_id', 'section', 'score', 'weight'];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }
}
