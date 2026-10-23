<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryAuditPersona extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'problems' => 'array', 'desired_outcomes' => 'array', 'motivations' => 'array',
            'purchase_triggers' => 'array', 'objections' => 'array', 'products' => 'array',
            'evidence' => 'array', 'confidence' => 'float', 'position' => 'integer', 'confirmed' => 'boolean', 'manually_edited' => 'boolean', 'edited_at' => 'datetime',
        ];
    }

    public function audit() { return $this->belongsTo(AdLibraryAudit::class, 'ad_library_audit_id'); }
    public function creatives() { return $this->belongsToMany(AdLibraryAuditCreative::class, 'ad_library_audit_creative_persona')->withPivot('role')->withTimestamps(); }
    public function editor() { return $this->belongsTo(User::class, 'edited_by'); }
}
