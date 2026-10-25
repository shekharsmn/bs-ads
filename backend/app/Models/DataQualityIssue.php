<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataQualityIssue extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['evidence' => 'array', 'first_detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function adAccount()
    {
        return $this->belongsTo(AdAccount::class);
    }
}
