<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeminiRequestLog extends Model
{
    protected $fillable = [
        'user_id', 'report_id', 'meta_account_id',
        'ad_name', 'call_type', 'input_tokens', 'output_tokens',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function metaAccount()
    {
        return $this->belongsTo(MetaAccount::class);
    }
}
