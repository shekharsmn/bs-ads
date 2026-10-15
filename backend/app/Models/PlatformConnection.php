<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;

class PlatformConnection extends Model
{
    protected $fillable = ['client_id', 'platform', 'name', 'scope', 'manager_external_id', 'credentials', 'status', 'last_synced_at', 'last_healthy_at', 'last_error'];
    protected $hidden = ['credentials'];
    protected function casts(): array
    {
        return ['platform' => Platform::class, 'credentials' => 'encrypted:array', 'status' => ConnectionStatus::class, 'last_synced_at' => 'datetime', 'last_healthy_at' => 'datetime'];
    }
    public function client() { return $this->belongsTo(Client::class); }
    public function adAccounts() { return $this->hasMany(AdAccount::class); }
}
