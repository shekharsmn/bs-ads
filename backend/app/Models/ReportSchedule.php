<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSchedule extends Model
{
    protected $fillable = ['client_id', 'ad_account_id', 'scope_key', 'report_template_id', 'frequency', 'weekday', 'day_of_month', 'local_time', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function adAccount()
    {
        return $this->belongsTo(AdAccount::class);
    }
}
