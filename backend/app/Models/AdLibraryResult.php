<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdLibraryResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer', 'advertiser_likes' => 'integer', 'is_active' => 'boolean',
            'first_shown_at' => 'date', 'last_shown_at' => 'date', 'platforms' => 'array',
            'image_urls' => 'array', 'video_urls' => 'array', 'reach_breakdown' => 'array',
            'target_locations' => 'array', 'raw_payload' => 'array', 'eu_reach' => 'integer',
            'uk_reach' => 'integer', 'advertiser_ig_followers' => 'integer',
        ];
    }

    public function run()
    {
        return $this->belongsTo(AdLibraryRun::class, 'ad_library_run_id');
    }
}
