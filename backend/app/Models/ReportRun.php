<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    protected $fillable = ['client_id', 'ad_account_id', 'report_schedule_id', 'report_template_id', 'frequency', 'period_start', 'period_end', 'status', 'delivery_status', 'snapshot', 'template_snapshot', 'public_token', 'expires_at', 'generated_at', 'delivered_at', 'error_message', 'delivery_error', 'methodology_key', 'methodology_version', 'methodology_snapshot'];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'snapshot' => 'array', 'template_snapshot' => 'array', 'methodology_snapshot' => 'array', 'expires_at' => 'datetime', 'generated_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function template()
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function adAccount()
    {
        return $this->belongsTo(AdAccount::class);
    }
}
