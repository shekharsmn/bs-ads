<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryRun extends Model
{
    protected $fillable = ['ad_library_search_id', 'requested_by', 'provider', 'status', 'result_count', 'reported_total', 'stop_reason', 'capped', 'error_message', 'provider_metadata', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['result_count' => 'integer', 'reported_total' => 'integer', 'capped' => 'boolean', 'provider_metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function search()
    {
        return $this->belongsTo(AdLibrarySearch::class, 'ad_library_search_id')->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function results()
    {
        return $this->hasMany(AdLibraryResult::class);
    }

    public function audits()
    {
        return $this->hasMany(AdLibraryAudit::class, 'ad_library_run_id');
    }
}
