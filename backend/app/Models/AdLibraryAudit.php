<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryAudit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context_sources' => 'array', 'website_context' => 'array', 'insights' => 'array',
            'insights_stale' => 'boolean', 'prompt_version' => 'integer', 'schema_version' => 'integer',
            'progress_total' => 'integer', 'progress_completed' => 'integer', 'progress_failed' => 'integer',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'estimated_cost' => 'decimal:6',
            'personas_confirmed_at' => 'datetime', 'grid_confirmed_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function run()
    {
        return $this->belongsTo(AdLibraryRun::class, 'ad_library_run_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function personas()
    {
        return $this->hasMany(AdLibraryAuditPersona::class)->orderBy('position');
    }

    public function creatives()
    {
        return $this->hasMany(AdLibraryAuditCreative::class)->orderBy('position');
    }

    public function aiRequests()
    {
        return $this->hasMany(AdLibraryAuditAiRequest::class);
    }
}
