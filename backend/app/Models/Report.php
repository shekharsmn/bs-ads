<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'user_id', 'platform', 'meta_account_id', 'google_account_id', 'status', 'health_score',
        'grade', 'period', 'error_message', 'started_at', 'completed_at',
        'cancel_requested_at', 'cancelled_at',
        'gemini_input_tokens', 'gemini_output_tokens', 'google_snapshot',
        'public_token', 'meta_snapshot',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'google_snapshot' => 'array',
        'meta_snapshot' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function metaAccount()
    {
        return $this->belongsTo(MetaAccount::class);
    }

    public function googleAccount()
    {
        return $this->belongsTo(GoogleAccount::class);
    }

    public function sectionScores()
    {
        return $this->hasMany(ReportSectionScore::class);
    }

    public function checks()
    {
        return $this->hasMany(ReportCheck::class);
    }

    public function ads()
    {
        return $this->hasMany(ReportAd::class);
    }

    public function creativeSummary()
    {
        return $this->hasOne(CreativeSummary::class);
    }
}
