<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = ['name', 'slug', 'timezone', 'currency', 'objective', 'goals', 'chat_visibility', 'google_chat_webhook', 'active'];

    protected $hidden = ['google_chat_webhook'];
    protected $appends = ['google_chat_webhook_configured'];

    protected function casts(): array
    {
        return ['goals' => 'array', 'google_chat_webhook' => 'encrypted', 'active' => 'boolean'];
    }

    public function users() { return $this->belongsToMany(User::class)->withTimestamps(); }
    public function connections() { return $this->hasMany(PlatformConnection::class); }
    public function adAccounts() { return $this->hasMany(AdAccount::class); }
    public function alertRules() { return $this->hasMany(AlertRule::class); }
    public function conversionMappings() { return $this->hasMany(ConversionMapping::class); }
    public function incidents() { return $this->hasMany(AlertIncident::class); }
    public function reportSchedules() { return $this->hasMany(ReportSchedule::class); }
    public function getGoogleChatWebhookConfiguredAttribute(): bool { return filled($this->google_chat_webhook); }
}
