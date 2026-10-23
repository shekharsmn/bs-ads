<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryAuditAiRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['prompt_version' => 'integer', 'schema_version' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'estimated_cost' => 'decimal:6', 'input_price' => 'decimal:6', 'output_price' => 'decimal:6', 'latency_ms' => 'integer'];
    }

    public function audit() { return $this->belongsTo(AdLibraryAudit::class, 'ad_library_audit_id'); }
    public function creative() { return $this->belongsTo(AdLibraryAuditCreative::class, 'ad_library_audit_creative_id'); }
}
