<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;

class AdAccount extends Model
{
    protected $fillable = ['client_id', 'platform_connection_id', 'platform', 'external_id', 'manager_external_id', 'name', 'currency', 'timezone', 'status', 'monthly_budget', 'last_collected_at', 'last_error'];

    protected function casts(): array
    {
        return ['platform' => Platform::class, 'monthly_budget' => 'decimal:4', 'last_collected_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function connection()
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function metrics()
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
