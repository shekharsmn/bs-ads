<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeminiRequestLog extends Model
{
    protected $fillable = [
        'user_id', 'report_id', 'meta_account_id',
        'ad_name', 'call_type', 'input_tokens', 'output_tokens',
        'provider', 'model', 'input_price', 'output_price', 'estimated_cost',
        'status', 'latency_ms', 'error_message',
        'prompt_version', 'schema_version',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer', 'output_tokens' => 'integer',
            'input_price' => 'decimal:6', 'output_price' => 'decimal:6',
            'estimated_cost' => 'decimal:6', 'latency_ms' => 'integer',
            'prompt_version' => 'integer', 'schema_version' => 'integer',
        ];
    }

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function metaAccount()
    {
        return $this->belongsTo(MetaAccount::class);
    }
}
