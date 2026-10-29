<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ad extends Model
{
    protected $fillable = ['ad_group_id', 'external_id', 'name', 'status', 'type'];

    public function adGroup()
    {
        return $this->belongsTo(AdGroup::class);
    }

    public function metrics()
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
