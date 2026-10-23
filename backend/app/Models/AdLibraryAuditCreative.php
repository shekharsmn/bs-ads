<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryAuditCreative extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'library_ad_ids' => 'array', 'source_snapshot' => 'array', 'evidence' => 'array',
            'raw_ai_response' => 'array', 'confidence' => 'float', 'position' => 'integer',
            'manually_edited' => 'boolean', 'edited_at' => 'datetime', 'reviewed_at' => 'datetime',
        ];
    }

    public function audit() { return $this->belongsTo(AdLibraryAudit::class, 'ad_library_audit_id'); }
    public function sourceResult() { return $this->belongsTo(AdLibraryResult::class, 'ad_library_result_id'); }
    public function personas() { return $this->belongsToMany(AdLibraryAuditPersona::class, 'ad_library_audit_creative_persona')->withPivot('role')->withTimestamps(); }
    public function editor() { return $this->belongsTo(User::class, 'edited_by'); }
}
