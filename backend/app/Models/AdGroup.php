<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdGroup extends Model
{
    protected $fillable = ['campaign_id', 'external_id', 'name', 'status', 'type', 'daily_budget'];

    protected function casts(): array
    {
        return ['daily_budget' => 'decimal:4'];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function ads()
    {
        return $this->hasMany(Ad::class);
    }

    public function metrics()
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
