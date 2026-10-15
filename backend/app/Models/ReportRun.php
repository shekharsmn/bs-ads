<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    protected $fillable = ['client_id', 'report_schedule_id', 'frequency', 'period_start', 'period_end', 'status', 'snapshot', 'public_token', 'expires_at', 'generated_at', 'error_message'];
    protected $hidden = ['public_token'];
    protected function casts(): array { return ['period_start' => 'date', 'period_end' => 'date', 'snapshot' => 'array', 'expires_at' => 'datetime', 'generated_at' => 'datetime']; }
    public function client() { return $this->belongsTo(Client::class); }
}
